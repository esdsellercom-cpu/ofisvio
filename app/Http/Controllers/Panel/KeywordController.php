<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\SeoKeyword;
use App\Models\Website;
use App\Seo\HealthCenter;
use App\Services\AuthorizationService;
use App\Services\ContentService;
use App\Services\KeywordService;
use App\Services\LinkGraphService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Keyword Intelligence + Internal Linking Engine (faz 60c). seo.view görür; seo.edit kelime ekler/siler ve
 * manuel bağlantı kuralı yazar. Hacim/sıralama verisi yalnız bağlı Search Console'dan (60d) gelir.
 */
class KeywordController extends Controller
{
    public function __construct(
        private readonly KeywordService $keywords,
        private readonly LinkGraphService $links,
        private readonly ContentService $contents,
        private readonly HealthCenter $center,
        private readonly AuthorizationService $authorization,
    ) {}

    public function home(): RedirectResponse
    {
        return redirect()->route('panel.seo.keywords', $this->contents->defaultWebsite());
    }

    public function index(Request $request, Website $website): View
    {
        return view('panel.seo.keywords', [
            'website' => $website,
            'analysis' => $this->keywords->analysis($website),
            'targets' => $this->keywords->targetOptions($website),
            'roles' => SeoKeyword::ROLES,
            'intents' => SeoKeyword::INTENTS,
            'canEdit' => $this->authorization->can($request->user(), 'seo.edit'),
            'integrations' => $this->center->integrations(),
        ]);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'min:2', 'max:120'],
            'role' => ['required', Rule::in(array_keys(SeoKeyword::ROLES))],
            'intent' => ['required', Rule::in(array_keys(SeoKeyword::INTENTS))],
            'cluster' => ['nullable', 'string', 'max:80'],
            'target' => ['nullable', 'string', 'max:320'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $this->keywords->create($request->user(), $website, $data);
        } catch (DomainException $e) {
            return back()->withErrors(['keyword' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Anahtar kelime eklendi.');
    }

    public function destroy(Request $request, Website $website, int $keyword): RedirectResponse
    {
        try {
            $this->keywords->delete($request->user(), $website, $keyword);
        } catch (DomainException $e) {
            return back()->withErrors(['keyword' => $e->getMessage()]);
        }

        return back()->with('status', 'Anahtar kelime silindi.');
    }

    public function linksHome(): RedirectResponse
    {
        return redirect()->route('panel.seo.links', $this->contents->defaultWebsite());
    }

    public function links(Request $request, Website $website): View
    {
        $graph = $this->links->graph($website);
        $sort = (string) $request->query('sirala', 'in');
        $nodes = array_values($graph['nodes']);
        usort($nodes, fn (array $a, array $b) => match ($sort) {
            'out' => $a['out'] <=> $b['out'],
            'density' => ($a['density'] ?? -1) <=> ($b['density'] ?? -1),
            'broken' => $b['broken'] <=> $a['broken'],
            default => $a['in'] <=> $b['in'],
        } ?: strcmp($a['path'], $b['path']));

        return view('panel.seo.links', [
            'website' => $website,
            'graph' => $graph,
            'nodes' => $nodes,
            'sort' => $sort,
            'canEdit' => $this->authorization->can($request->user(), 'seo.edit'),
        ]);
    }

    public function storeRule(Request $request, Website $website): RedirectResponse
    {
        $data = $request->validate(['keyword' => ['required', 'string', 'min:2', 'max:80'], 'url' => ['required', 'string', 'max:300']]);

        try {
            $this->links->addRule($request->user(), $website, $data['keyword'], $data['url']);
        } catch (DomainException $e) {
            return back()->withErrors(['keyword' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Manuel bağlantı kuralı eklendi; içerik gövdelerinde ilk geçtiği yerde bağlantıya çevrilir.');
    }

    public function destroyRule(Request $request, Website $website): RedirectResponse
    {
        $data = $request->validate(['keyword' => ['required', 'string', 'max:80']]);
        $this->links->removeRule($request->user(), $website, $data['keyword']);

        return back()->with('status', 'Kural kaldırıldı.');
    }
}
