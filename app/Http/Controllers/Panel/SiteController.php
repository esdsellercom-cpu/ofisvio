<?php

namespace App\Http\Controllers\Panel;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContentRequest;
use App\Http\Requests\UpdateNavigationRequest;
use App\Models\Company;
use App\Models\Content;
use App\Services\ContentCache;
use App\Services\ContentService;
use App\Services\TenantContext;
use App\Services\WebsiteService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Müşteri sitesi (faz 10): organizasyonun web sitesindeki içerik, müşteri
 * panelinden. Matris müşteri rollerine yalnızca company kapsamlı
 * content.edit / content.review / content.schedule verir — oluşturma, doğrudan
 * yayın, onay ve arşiv personelde kalır. Müşteri için yayın = ZAMANLAMA
 * (content:publish-scheduled zamanı gelince yayınlar/birleştirir).
 *
 * Tenant sınırı: {company} permission middleware'inde aktif organizasyona
 * karşı doğrulanır; içerik ContentService::findForOrganization ile o
 * organizasyonun sitelerine süzülür — başka organizasyonun içeriği 404.
 * {content} model binding DEĞİLDİR (int); yalnızca süzülmüş yoldan çözülür.
 */
class SiteController extends Controller
{
    public function __construct(
        private readonly ContentService $contents,
        private readonly WebsiteService $websites,
        private readonly ContentCache $cache,
        private readonly TenantContext $context,
    ) {}

    private function organizationId(Request $request, Company $company): int
    {
        return (int) $this->context->toArray($request->user(), $company->id)['organization_id'];
    }

    private function find(Request $request, Company $company, int $id): Content
    {
        $content = $this->contents->findForOrganization($this->organizationId($request, $company), $id);

        abort_if($content === null, 404);

        return $content;
    }

    /** @return array<string, bool> */
    private function abilities(Request $request, Company $company): array
    {
        $user = $request->user();

        return [
            'edit' => $user->can('content.edit', $company),
            'review' => $user->can('content.review', $company),
            'schedule' => $user->can('content.schedule', $company),
        ];
    }

    public function index(Request $request, Company $company): View
    {
        $organizationId = $this->organizationId($request, $company);

        return view('panel.site.index', [
            'company' => $company,
            'websites' => $this->websites->forOrganization($organizationId),
            'items' => $this->contents->listForOrganization($organizationId),
        ]);
    }

    /** Site menüsü: organizasyonun sitesi ({website} int, süzülür). */
    public function menu(Request $request, Company $company, int $website): View
    {
        $site = $this->websites->findForOrganization($this->organizationId($request, $company), $website);

        abort_if($site === null, 404);

        return view('panel.content.menu', [
            'website' => $site,
            'pages' => $this->contents->pagesFor($site),
            'formAction' => route('panel.companies.site.menu.update', [$company, $site->id]),
            'themeAction' => route('panel.companies.site.theme', [$company, $site->id]),
            'linksAction' => route('panel.companies.site.links', [$company, $site->id]),
            'backUrl' => route('panel.companies.site.index', $company),
            'backLabel' => 'Web sitesi',
        ]);
    }

    public function saveLinks(Request $request, Company $company, int $website): RedirectResponse
    {
        $site = $this->websites->findForOrganization($this->organizationId($request, $company), $website);

        abort_if($site === null, 404);

        $validated = $request->validate(['links' => ['nullable', 'string', 'max:2000']]);

        try {
            $this->websites->updateNavLinks($site, (string) ($validated['links'] ?? ''));
        } catch (DomainException $e) {
            return back()->withErrors(['links' => $e->getMessage()])->withInput();
        }

        $this->cache->invalidate($site);

        return redirect()->route('panel.companies.site.menu', [$company, $site->id])->with('status', 'Bağlantılar kaydedildi.');
    }

    public function saveTheme(Request $request, Company $company, int $website): RedirectResponse
    {
        $site = $this->websites->findForOrganization($this->organizationId($request, $company), $website);

        abort_if($site === null, 404);

        $validated = $request->validate(['theme' => ['required', 'string', Rule::in(array_keys((array) config('ofisvio.themes')))]]);

        $this->websites->updateTheme($site, $validated['theme']);
        $this->cache->invalidate($site);

        return redirect()->route('panel.companies.site.menu', [$company, $site->id])->with('status', 'Tema uygulandı.');
    }

    public function saveMenu(UpdateNavigationRequest $request, Company $company, int $website): RedirectResponse
    {
        $site = $this->websites->findForOrganization($this->organizationId($request, $company), $website);

        abort_if($site === null, 404);

        $this->contents->updateNavigation($site, $request->layout());

        return redirect()->route('panel.companies.site.menu', [$company, $site->id])->with('status', 'Menü kaydedildi.');
    }

    public function show(Request $request, Company $company, int $content): View
    {
        $model = $this->find($request, $company, $content);

        return view('panel.site.show', [
            'company' => $company,
            'content' => $model,
            'draft' => $model->draft,
            'revisions' => $this->contents->revisionsOf($model),
            'suggestions' => $this->contents->linkSuggestions($model),
            'can' => $this->abilities($request, $company),
        ]);
    }

    public function edit(Request $request, Company $company, int $content): View|RedirectResponse
    {
        $model = $this->find($request, $company, $content);

        if ($model->status !== ContentStatus::DRAFT) {
            return redirect()->route('panel.companies.site.show', [$company, $model->id])
                ->withErrors(['status' => 'Yalnızca taslak düzenlenir; yayındaki sayfa için çalışma taslağı açın.']);
        }

        return view('panel.content.form', [
            'content' => $model,
            'website' => $model->website,
            'kind' => $model->kind,
            'formAction' => route('panel.companies.site.update', [$company, $model->id]),
            'indexUrl' => route('panel.companies.site.index', $company),
            'cancelUrl' => route('panel.companies.site.show', [$company, $model->id]),
        ]);
    }

    public function update(StoreContentRequest $request, Company $company, int $content): RedirectResponse
    {
        $model = $this->find($request, $company, $content);

        try {
            $this->contents->update($request->user(), $model, $request->validated());
        } catch (DomainException $e) {
            return back()->withErrors(['title' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.companies.site.show', [$company, $model->id])->with('status', 'Kaydedildi (yeni revizyon).');
    }

    public function submit(Request $request, Company $company, int $content): RedirectResponse
    {
        return $this->move($request, $company, $content, ContentStatus::IN_REVIEW, 'İncelemeye gönderildi.');
    }

    public function reject(Request $request, Company $company, int $content): RedirectResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:2000']]);

        return $this->move($request, $company, $content, ContentStatus::DRAFT, 'Taslağa geri gönderildi.');
    }

    public function schedule(Request $request, Company $company, int $content): RedirectResponse
    {
        $validated = $request->validate(['scheduled_for' => ['required', 'date', 'after:now']]);

        return $this->move($request, $company, $content, ContentStatus::SCHEDULED, 'Zamanlandı; zamanı gelince yayınlanır.', Carbon::parse($validated['scheduled_for'], config('app.timezone')));
    }

    public function restore(Request $request, Company $company, int $content): RedirectResponse
    {
        return $this->move($request, $company, $content, ContentStatus::DRAFT, 'Taslağa alındı.');
    }

    // --- Çalışma taslağı (yayındaki sayfa) ---------------------------------

    public function openDraft(Request $request, Company $company, int $content): RedirectResponse
    {
        $model = $this->find($request, $company, $content);

        try {
            $this->contents->openDraft($request->user(), $model);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('panel.companies.site.draft.edit', [$company, $model->id])->with('status', 'Çalışma taslağı açıldı; yayındaki sayfa değişmedi.');
    }

    public function editDraft(Request $request, Company $company, int $content): View|RedirectResponse
    {
        $model = $this->find($request, $company, $content);
        $draft = $model->draft;

        if ($draft === null || $draft->status !== ContentStatus::DRAFT) {
            return redirect()->route('panel.companies.site.show', [$company, $model->id])
                ->withErrors(['status' => $draft === null ? 'Bu sayfanın çalışma taslağı yok.' : 'Taslak incelemede; düzenlemek için önce geri alın.']);
        }

        return view('panel.content.form', [
            'content' => $model,
            'draft' => $draft,
            'website' => $model->website,
            'kind' => $model->kind,
            'formAction' => route('panel.companies.site.draft.update', [$company, $model->id]),
            'indexUrl' => route('panel.companies.site.index', $company),
            'cancelUrl' => route('panel.companies.site.show', [$company, $model->id]),
        ]);
    }

    public function updateDraft(StoreContentRequest $request, Company $company, int $content): RedirectResponse
    {
        $model = $this->find($request, $company, $content);
        $draft = $model->draft;

        if ($draft === null) {
            return redirect()->route('panel.companies.site.show', [$company, $model->id])->withErrors(['status' => 'Bu sayfanın çalışma taslağı yok.']);
        }

        try {
            $this->contents->updateDraft($request->user(), $draft, $request->validated());
        } catch (DomainException $e) {
            return back()->withErrors(['title' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.companies.site.show', [$company, $model->id])->with('status', 'Çalışma taslağı kaydedildi.');
    }

    public function submitDraft(Request $request, Company $company, int $content): RedirectResponse
    {
        return $this->moveDraft($request, $company, $content, ContentStatus::IN_REVIEW, 'Taslak incelemeye gönderildi.');
    }

    public function rejectDraft(Request $request, Company $company, int $content): RedirectResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:2000']]);

        return $this->moveDraft($request, $company, $content, ContentStatus::DRAFT, 'Taslak geri gönderildi.');
    }

    public function scheduleDraft(Request $request, Company $company, int $content): RedirectResponse
    {
        $validated = $request->validate(['scheduled_for' => ['required', 'date', 'after:now']]);

        return $this->moveDraft($request, $company, $content, ContentStatus::SCHEDULED, 'Taslak zamanlandı; zamanı gelince yayındaki sayfayla birleşir.', Carbon::parse($validated['scheduled_for'], config('app.timezone')));
    }

    public function restoreDraft(Request $request, Company $company, int $content): RedirectResponse
    {
        return $this->moveDraft($request, $company, $content, ContentStatus::DRAFT, 'Taslak düzenlenebilir duruma alındı.');
    }

    public function discardDraft(Request $request, Company $company, int $content): RedirectResponse
    {
        $model = $this->find($request, $company, $content);

        if ($model->draft !== null) {
            $this->contents->discardDraft($model->draft);
        }

        return redirect()->route('panel.companies.site.show', [$company, $model->id])->with('status', 'Çalışma taslağı silindi; yayındaki sayfa olduğu gibi kaldı.');
    }

    private function move(Request $request, Company $company, int $content, ContentStatus $target, string $message, ?Carbon $scheduledFor = null): RedirectResponse
    {
        $model = $this->find($request, $company, $content);

        try {
            $this->contents->transition($request->user(), $model, $target, $request->input('note'), $scheduledFor);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('panel.companies.site.show', [$company, $model->id])->with('status', $message);
    }

    private function moveDraft(Request $request, Company $company, int $content, ContentStatus $target, string $message, ?Carbon $scheduledFor = null): RedirectResponse
    {
        $model = $this->find($request, $company, $content);
        $draft = $model->draft;

        if ($draft === null) {
            return back()->withErrors(['status' => 'Bu sayfanın çalışma taslağı yok.']);
        }

        try {
            $this->contents->transitionDraft($request->user(), $draft, $target, $request->input('note'), $scheduledFor);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('panel.companies.site.show', [$company, $model->id])->with('status', $message);
    }
}
