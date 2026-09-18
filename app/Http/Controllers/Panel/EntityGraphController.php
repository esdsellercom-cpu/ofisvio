<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Seo\GeoAnswers;
use App\Services\AuthorizationService;
use App\Services\ContentService;
use App\Services\EntityGraphService;
use App\Services\GeoService;
use App\Services\SeoSettingsService;
use App\Services\ServiceService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Entity / Knowledge Graph + GEO Manager (faz 60b).
 *
 *   graph      seo.view  — varlıklar, ilişkiler, boşluklar; Article → Service/Location düzenleyici
 *   relations  seo.edit  — yazının hizmet/lokasyon ilişkileri
 *   topic      seo.edit  — Topic → Entity
 *   unlink     seo.edit
 *   geo        seo.view  — GEO Manager: hizmet cevap kapsamı, lokasyon varlık alanları, ayar bağlantıları
 */
class EntityGraphController extends Controller
{
    public function __construct(
        private readonly EntityGraphService $graph,
        private readonly ContentService $contents,
        private readonly ServiceService $services,
        private readonly GeoService $geo,
        private readonly SeoSettingsService $settings,
        private readonly AuthorizationService $authorization,
    ) {}

    public function home(): RedirectResponse
    {
        return redirect()->route('panel.seo.entities', $this->contents->defaultWebsite());
    }

    public function graph(Request $request, Website $website): View
    {
        $graph = $this->graph->graph($website);
        $selectedId = (int) $request->query('yazi', 0);
        $selected = $selectedId > 0 ? $graph['posts']->firstWhere('id', $selectedId) ?? $this->contents->livePages($website)->firstWhere('id', $selectedId) : null;

        return view('panel.seo.entities', [
            'website' => $website,
            'graph' => $graph,
            'selected' => $selected,
            'selectedRelations' => $selected !== null ? ($graph['by_content'][$selected->id] ?? []) : [],
            'pages' => $this->contents->livePages($website),
            'serviceOptions' => $website->is_default ? $this->services->all() : collect(),
            'locationOptions' => $website->is_default ? $this->geo->allLocations() : collect(),
            'canEdit' => $this->authorization->can($request->user(), 'seo.edit'),
            'settings' => $this->settings->for($website),
        ]);
    }

    public function relations(Request $request, Website $website): RedirectResponse
    {
        $data = $request->validate([
            'content_id' => ['required', 'integer'],
            'services' => ['nullable', 'array'], 'services.*' => ['integer'],
            'locations' => ['nullable', 'array'], 'locations.*' => ['integer'],
        ]);
        $content = $this->contents->findForWebsite($website, (int) $data['content_id']);

        if ($content === null) {
            return back()->withErrors(['content_id' => 'İçerik bulunamadı.']);
        }

        try {
            $this->graph->syncContentRelations($request->user(), $website, $content, array_map('intval', $data['services'] ?? []), array_map('intval', $data['locations'] ?? []));
        } catch (DomainException $e) {
            return back()->withErrors(['content_id' => $e->getMessage()]);
        }

        return redirect()->route('panel.seo.entities', [$website, 'yazi' => $content->id])->with('status', '"'.$content->title.'" ilişkileri güncellendi.');
    }

    public function topic(Request $request, Website $website): RedirectResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:80'],
            'to_type' => ['required', Rule::in(['service', 'location', 'content'])],
            'to_id' => ['required', 'integer'],
        ]);

        try {
            $this->graph->linkTopic($request->user(), $website, $data['topic'], $data['to_type'], (int) $data['to_id']);
        } catch (DomainException $e) {
            return back()->withErrors(['topic' => $e->getMessage()]);
        }

        return back()->with('status', 'Konu ilişkisi eklendi.');
    }

    public function unlink(Request $request, Website $website, int $relation): RedirectResponse
    {
        try {
            $this->graph->unlink($request->user(), $website, $relation);
        } catch (DomainException $e) {
            return back()->withErrors(['topic' => $e->getMessage()]);
        }

        return back()->with('status', 'İlişki kaldırıldı.');
    }

    public function geoHome(): RedirectResponse
    {
        return redirect()->route('panel.seo.geo', $this->contents->defaultWebsite());
    }

    public function geo(Website $website): View
    {
        $services = $website->is_default ? $this->services->all()->map(fn ($s) => ['model' => $s, 'filled' => GeoAnswers::filled($s->answers), 'faq' => count($s->faqPairs()), 'total' => count(GeoAnswers::FIELDS) + 3]) : collect();

        return view('panel.seo.geo', [
            'website' => $website,
            'services' => $services,
            'locations' => $website->is_default ? $this->geo->allLocations() : collect(),
            'geoAudit' => $website->is_default ? $this->geo->audit() : [],
            'settings' => $this->settings->for($website),
            'fields' => GeoAnswers::FIELDS,
        ]);
    }
}
