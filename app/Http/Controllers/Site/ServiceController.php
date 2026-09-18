<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Seo\GeoAnswers;
use App\Services\CurrentWebsite;
use App\Services\EntityGraphService;
use App\Services\LandingPageService;
use App\Services\ServiceService;
use Illuminate\Contracts\View\View;

/** Hizmet sayfaları (faz 4): liste + detay (sunan lokasyonlar, rezervasyona bağlı odalar). */
class ServiceController extends Controller
{
    public function __construct(
        private readonly ServiceService $services,
        private readonly CurrentWebsite $website,
        private readonly LandingPageService $landing,
        private readonly EntityGraphService $entities,
    ) {}

    public function index(): View
    {
        abort_if($this->website->isTenantSite(), 404);

        return view('site.services', ['services' => $this->services->active($this->website->get())->load('cover')]);
    }

    public function show(string $slug): View
    {
        abort_if($this->website->isTenantSite(), 404);
        $service = $this->services->findActive($slug);
        abort_if($service === null, 404);

        $website = $this->website->get();
        $answers = is_array($service->answers) ? $service->answers : [];

        return view('site.service', [
            'service' => $service,
            'locations' => $this->services->locationsFor($service),
            'rooms' => $this->services->roomsFor($service),
            // GEO Answer Engine + Knowledge Graph (faz 60b): yalnız DB'deki cevaplar ve ilişkiler.
            'sections' => GeoAnswers::sections($answers),
            'faq' => $service->faqPairs(),
            'relatedServices' => $this->services->related($service),
            'relatedLocations' => $this->services->relatedLocations($service),
            'cityPages' => $website === null ? collect() : $this->landing->live($website, $service->id),
            'articles' => $website === null ? collect() : $this->entities->contentsAbout($website, 'service', $service->id),
        ]);
    }
}
