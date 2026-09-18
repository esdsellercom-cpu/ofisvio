<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Integrations\Ai\AiProviderInterface;
use App\Models\AiJob;
use App\Models\AiPrompt;
use App\Models\ContentRefreshCandidate;
use App\Models\Website;
use App\Services\AiContentService;
use App\Services\AuthorizationService;
use App\Services\ContentRefreshService;
use App\Services\ContentService;
use App\Services\GeoService;
use App\Services\ServiceService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * AI Content Engine + Prompt Registry + Kullanım & maliyet + İçerik yenileme (faz 60e).
 *
 *   ai_content.generate  — iş oluşturma, taslak/denetim aşamaları, prompt sürümü
 *   ai_content.review    — inceleme (düzenleme/ret)
 *   ai_content.approve   — onay (dört göz)
 *   ai_content.publish   — zamanlama/yayın (CMS'e taslak/zamanlanmış içerik ya da çalışma taslağı)
 *   seo.audit            — yenileme adayı tespiti; seo.edit karar
 */
class AiContentController extends Controller
{
    public function __construct(
        private readonly AiContentService $ai,
        private readonly ContentRefreshService $refresh,
        private readonly ContentService $contents,
        private readonly ServiceService $services,
        private readonly GeoService $geo,
        private readonly AuthorizationService $authorization,
        private readonly AiProviderInterface $provider,
    ) {}

    public function home(): RedirectResponse
    {
        return redirect()->route('panel.seo.ai.index', $this->contents->defaultWebsite());
    }

    public function index(Request $request, Website $website): View
    {
        $user = $request->user();

        return view('panel.seo.ai.index', [
            'website' => $website,
            'jobs' => $this->ai->jobs($website),
            'topics' => $this->ai->discoverTopics($website),
            'services' => $website->is_default ? $this->services->all() : collect(),
            'locations' => $website->is_default ? $this->geo->allLocations() : collect(),
            'available' => $this->provider->available(),
            'can' => ['generate' => $this->authorization->can($user, 'ai_content.generate'), 'review' => $this->authorization->can($user, 'ai_content.review'), 'approve' => $this->authorization->can($user, 'ai_content.approve'), 'publish' => $this->authorization->can($user, 'ai_content.publish')],
            'stages' => AiJob::STAGES,
        ]);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'min:3', 'max:200'],
            'audience' => ['nullable', 'string', 'max:300'],
            'intent' => ['nullable', 'string', 'max:60'],
            'keywords' => ['nullable', 'string', 'max:300'],
            'services' => ['nullable', 'array'], 'services.*' => ['integer'],
            'locations' => ['nullable', 'array'], 'locations.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'source_content_id' => ['nullable', 'integer'],
        ]);
        $source = isset($data['source_content_id']) ? $this->contents->findForWebsite($website, (int) $data['source_content_id']) : null;

        try {
            $job = $this->ai->createJob($request->user(), $website, $data['topic'], $data, $source);

            if ($source !== null) {
                $candidate = $this->refresh->candidates($website, 'all')->firstWhere('content_id', $source->id);

                if ($candidate !== null) {
                    $this->refresh->attachJob($request->user(), $candidate, $job->id);
                }
            }
        } catch (DomainException $e) {
            return back()->withErrors(['topic' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.seo.ai.show', [$website, $job])->with('status', 'Brief kaydedildi; sıradaki adım AI taslak.');
    }

    public function show(Request $request, Website $website, int $job): View
    {
        $model = $this->ai->find($website, $job);
        abort_if($model === null, 404);
        $user = $request->user();

        return view('panel.seo.ai.show', [
            'website' => $website,
            'job' => $model,
            'stages' => AiJob::STAGES,
            'order' => AiJob::ORDER,
            'can' => ['generate' => $this->authorization->can($user, 'ai_content.generate'), 'review' => $this->authorization->can($user, 'ai_content.review'), 'approve' => $this->authorization->can($user, 'ai_content.approve'), 'publish' => $this->authorization->can($user, 'ai_content.publish')],
            'available' => $this->provider->available(),
        ]);
    }

    /** Sıradaki aşama (generate): draft → fact_check → seo → geo → duplicate. */
    public function step(Request $request, Website $website, int $job): RedirectResponse
    {
        $model = $this->ai->find($website, $job);
        abort_if($model === null, 404);
        $actor = $request->user();

        try {
            match ($model->stage) {
                'draft' => $this->ai->runDraft($actor, $model),
                'fact_check' => $this->ai->runFactCheck($actor, $model),
                'seo' => $this->ai->runSeo($actor, $model),
                'geo' => $this->ai->runGeo($actor, $model),
                'duplicate' => $this->ai->runDuplicate($actor, $model),
                default => throw new DomainException('Bu aşama otomatik adım değil; inceleme/onay/yayın insan eylemidir.'),
            };
        } catch (DomainException $e) {
            return back()->withErrors(['stage' => $e->getMessage()]);
        }

        return back()->with('status', 'Aşama tamamlandı: '.AiJob::STAGES[$model->fresh()->stage]);
    }

    public function review(Request $request, Website $website, int $job): RedirectResponse
    {
        $model = $this->ai->find($website, $job);
        abort_if($model === null, 404);
        $data = $request->validate(['title' => ['nullable', 'string', 'max:200'], 'excerpt' => ['nullable', 'string', 'max:300'], 'body' => ['nullable', 'string', 'max:60000'], 'meta_title' => ['nullable', 'string', 'max:70'], 'meta_description' => ['nullable', 'string', 'max:170'], 'note' => ['nullable', 'string', 'max:300'], 'decision' => ['required', Rule::in(['approve_review', 'reject'])]]);

        try {
            $this->ai->review($request->user(), $model, $data, $data['note'] ?? null, $data['decision'] === 'reject');
        } catch (DomainException $e) {
            return back()->withErrors(['stage' => $e->getMessage()]);
        }

        return back()->with('status', $data['decision'] === 'reject' ? 'İş reddedildi.' : 'İnceleme tamamlandı; onay bekliyor.');
    }

    public function approve(Request $request, Website $website, int $job): RedirectResponse
    {
        $model = $this->ai->find($website, $job);
        abort_if($model === null, 404);

        try {
            $this->ai->approve($request->user(), $model, $request->boolean('accept_risks'));
        } catch (DomainException $e) {
            return back()->withErrors(['stage' => $e->getMessage()]);
        }

        return back()->with('status', 'Onaylandı; zamanlama/yayın adımı.');
    }

    public function publish(Request $request, Website $website, int $job): RedirectResponse
    {
        $model = $this->ai->find($website, $job);
        abort_if($model === null, 404);
        $data = $request->validate(['scheduled_for' => ['nullable', 'date'], 'publish_now' => ['nullable', 'boolean']]);

        try {
            if ($model->stage === 'schedule') {
                $this->ai->schedule($request->user(), $model, ! empty($data['scheduled_for']) ? Carbon::parse($data['scheduled_for']) : null);
            }

            $this->ai->publish($request->user(), $model->fresh(), (bool) ($data['publish_now'] ?? false));
        } catch (DomainException $e) {
            return back()->withErrors(['stage' => $e->getMessage()]);
        }

        return back()->with('status', 'Tamamlandı: içerik CMS\'e aktarıldı.');
    }

    // ---- Prompt Registry ------------------------------------------------------------------------

    public function promptsHome(): RedirectResponse
    {
        return redirect()->route('panel.seo.ai.prompts', $this->contents->defaultWebsite());
    }

    public function prompts(Request $request, Website $website): View
    {
        return view('panel.seo.ai.prompts', ['website' => $website, 'prompts' => $this->ai->prompts(), 'keys' => AiPrompt::KEYS, 'canEdit' => $this->authorization->can($request->user(), 'ai_content.generate')]);
    }

    public function storePrompt(Request $request, Website $website): RedirectResponse
    {
        $data = $request->validate(['key' => ['required', Rule::in(array_keys(AiPrompt::KEYS))], 'name' => ['nullable', 'string', 'max:120'], 'system' => ['required', 'string', 'max:4000'], 'template' => ['required', 'string', 'max:12000'], 'model' => ['nullable', 'string', 'max:80']]);

        try {
            $prompt = $this->ai->newPromptVersion($request->user(), $data['key'], (string) ($data['name'] ?? ''), $data['system'], $data['template'], $data['model'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['template' => $e->getMessage()])->withInput();
        }

        return back()->with('status', AiPrompt::KEYS[$prompt->key].' → sürüm '.$prompt->version.' aktif.');
    }

    // ---- Kullanım & maliyet --------------------------------------------------------------------

    public function usageHome(): RedirectResponse
    {
        return redirect()->route('panel.seo.ai.usage', $this->contents->defaultWebsite());
    }

    public function usage(Website $website): View
    {
        return view('panel.seo.ai.usage', ['website' => $website, 'usage' => $this->ai->usage($website), 'stages' => AiJob::STAGES]);
    }

    // ---- İçerik yenileme -------------------------------------------------------------------------

    public function refreshHome(): RedirectResponse
    {
        return redirect()->route('panel.seo.refresh', $this->contents->defaultWebsite());
    }

    public function refresh(Request $request, Website $website): View
    {
        $status = (string) $request->query('durum', 'open');
        $user = $request->user();

        return view('panel.seo.refresh', [
            'website' => $website,
            'candidates' => $this->refresh->candidates($website, in_array($status, ['open', 'planned', 'done', 'ignored', 'all'], true) ? $status : 'open'),
            'status' => $status,
            'statuses' => ContentRefreshCandidate::STATUSES,
            'canDetect' => $this->authorization->can($user, 'seo.audit'),
            'canDecide' => $this->authorization->can($user, 'seo.edit'),
            'canGenerate' => $this->authorization->can($user, 'ai_content.generate'),
        ]);
    }

    public function detect(Request $request, Website $website): RedirectResponse
    {
        $count = $this->refresh->detect($website, $request->boolean('external'));

        return back()->with('status', $count.' yenileme adayı bulundu.');
    }

    public function decideRefresh(Request $request, Website $website, int $candidate): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(ContentRefreshCandidate::STATUSES))]]);

        try {
            $this->refresh->decide($request->user(), $website, $candidate, $data['status']);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Aday durumu güncellendi.');
    }
}
