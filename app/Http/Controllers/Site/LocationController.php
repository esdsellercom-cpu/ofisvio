<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\CurrentWebsite;
use App\Services\EntityGraphService;
use App\Services\GeoService;
use App\Services\LandingPageService;
use App\Services\LocationMediaService;
use Illuminate\Contracts\View\View;

/**
 * Lokasyon sayfaları (GEO, faz 16). Yalnızca yayındaki lokasyonlar; Ofisvio
 * vitrinine aittir (lokasyon operatörün şubesidir, tenant varlığı değil).
 */
class LocationController extends Controller
{
    public function __construct(
        private readonly GeoService $geo,
        private readonly CurrentWebsite $website,
        private readonly LocationMediaService $media,
        private readonly LandingPageService $landing,
        private readonly EntityGraphService $entities,
    ) {}

    public function index(): View
    {
        abort_if($this->website->isTenantSite(), 404); // lokasyonlar operatörün, müşteri sitesinde yok

        return view('site.locations', ['locations' => $this->geo->publishedLocations()->load('services')]);
    }

    public function show(string $slug): View
    {
        abort_if($this->website->isTenantSite(), 404);

        $location = $this->geo->findPublished($slug);

        abort_if($location === null, 404);

        $website = $this->website->get();

        return view('site.location', [
            'location' => $location->load(['cover', 'services']),
            'gallery' => $this->media->gallery($location),
            // Knowledge Graph (faz 60b): bu şubeye bağlı hizmet × şehir sayfaları ve yazılar.
            'cityPages' => $website === null ? collect() : $this->landing->live($website, null, $location->id),
            'articles' => $website === null ? collect() : $this->entities->contentsAbout($website, 'location', $location->id),
        ]);
    }
}
