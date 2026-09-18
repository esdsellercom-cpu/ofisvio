<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\SeoLandingPage;
use App\Models\Website;
use App\Services\AuthorizationService;
use App\Services\ContentService;
use App\Services\GeoService;
use App\Services\LandingPageService;
use App\Services\RedirectService;
use App\Services\ServiceService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Programatik SEO (faz 60b): hizmet × şehir sayfaları. Tek tek oluşturma (toplu üretim ucu bilinçli olarak yok);
 * yayın kalite kapısından geçer. seo.view listeler, seo.edit yazar, seo.publish yayınlar/kaldırır/siler.
 */
class LandingPageController extends Controller
{
    public function __construct(
        private readonly LandingPageService $landing,
        private readonly ContentService $contents,
        private readonly ServiceService $services,
        private readonly GeoService $geo,
        private readonly RedirectService $redirects,
        private readonly AuthorizationService $authorization,
    ) {}

    public function home(): RedirectResponse
    {
        return redirect()->route('panel.seo.landing.index', $this->contents->defaultWebsite());
    }

    public function index(Request $request, Website $website): View
    {
        $user = $request->user();

        return view('panel.seo.landing.index', [
            'website' => $website,
            'pages' => $this->landing->all($website),
            'canEdit' => $this->authorization->can($user, 'seo.edit'),
            'canPublish' => $this->authorization->can($user, 'seo.publish'),
            'minIntro' => LandingPageService::MIN_INTRO,
            'maxSimilarity' => (int) (LandingPageService::MAX_SIMILARITY * 100),
        ]);
    }

    public function create(Website $website): View
    {
        return $this->form($website, null);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        try {
            $page = $this->landing->create($request->user(), $website, $this->validated($request, true));
        } catch (DomainException $e) {
            return back()->withErrors(['intro' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.seo.landing.edit', [$website, $page])->with('status', 'Sayfa taslak olarak oluşturuldu. Kalite: '.$page->quality['score'].'/100'.($page->quality['ok'] ? ' — yayınlanabilir.' : ' — '.implode(' · ', $page->quality['issues'])));
    }

    public function edit(Website $website, int $page): View
    {
        $model = $this->landing->find($website, $page);
        abort_if($model === null, 404);

        return $this->form($website, $model);
    }

    public function update(Request $request, Website $website, int $page): RedirectResponse
    {
        $model = $this->landing->find($website, $page);
        abort_if($model === null, 404);

        try {
            $model = $this->landing->update($request->user(), $website, $model, $this->validated($request, false));
        } catch (DomainException $e) {
            return back()->withErrors(['intro' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Kaydedildi. Kalite: '.$model->quality['score'].'/100'.($model->quality['ok'] ? ' — yayınlanabilir.' : ' — '.implode(' · ', $model->quality['issues'])));
    }

    public function publish(Request $request, Website $website, int $page): RedirectResponse
    {
        $model = $this->landing->find($website, $page);
        abort_if($model === null, 404);

        try {
            $this->landing->publish($request->user(), $website, $model);
        } catch (DomainException $e) {
            return back()->withErrors(['intro' => $e->getMessage()]);
        }

        return back()->with('status', 'Yayınlandı: '.$model->path());
    }

    public function unpublish(Request $request, Website $website, int $page): RedirectResponse
    {
        $model = $this->landing->find($website, $page);
        abort_if($model === null, 404);
        $this->landing->unpublish($request->user(), $website, $model);

        return back()->with('status', 'Yayından kaldırıldı.');
    }

    public function confirmDelete(Website $website, int $page): View
    {
        $model = $this->landing->find($website, $page);
        abort_if($model === null, 404);

        // Ortak silme onayı (faz 54): yönlendirme seçimi.
        return view('panel.content.delete', [
            'label' => $model->title,
            'path' => $model->path(),
            'wasLive' => $model->published_at !== null,
            'suggestions' => $this->redirects->suggest($website, $model->path(), ['title' => $model->title, 'category' => $model->location->city, 'tags' => [], 'text' => mb_substr(trim((string) $model->meta_description.' '.$model->intro), 0, 4000), 'kind' => 'landing']),
            'action' => route('panel.seo.landing.destroy', [$website, $model]),
            'cancel' => route('panel.seo.landing.index', $website),
        ]);
    }

    public function destroy(Request $request, Website $website, int $page): RedirectResponse
    {
        $model = $this->landing->find($website, $page);
        abort_if($model === null, 404);
        $this->landing->delete($request->user(), $website, $model, $this->redirectChoice($request));

        return redirect()->route('panel.seo.landing.index', $website)->with('status', 'Sayfa silindi; eski adres URL geçmişine alındı.');
    }

    private function form(Website $website, ?SeoLandingPage $page): View
    {
        return view('panel.seo.landing.form', [
            'website' => $website,
            'page' => $page,
            'services' => $this->services->all(),
            'locations' => $this->geo->allLocations(),
            'variables' => ['{hizmet}', '{sehir}', '{sehir_da}', '{sube}', '{adres}', '{ilce}'],
            'minIntro' => LandingPageService::MIN_INTRO,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'service_id' => [$creating ? 'required' : 'nullable', 'integer'],
            'location_id' => [$creating ? 'required' : 'nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:120'],
            'meta_description' => ['nullable', 'string', 'max:200'],
            'intro' => ['required', 'string', 'max:6000'],
            'body' => ['nullable', 'string', 'max:20000'],
            'faq' => ['nullable', 'array', 'max:10'], 'faq.*.q' => ['nullable', 'string', 'max:200'], 'faq.*.a' => ['nullable', 'string', 'max:1000'],
            'is_indexable' => ['nullable', 'boolean'],
        ]);
    }
}
