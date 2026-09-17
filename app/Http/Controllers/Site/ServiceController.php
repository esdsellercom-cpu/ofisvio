<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\CurrentWebsite;
use App\Services\ServiceService;
use Illuminate\Contracts\View\View;

/** Hizmet sayfaları (faz 4): liste + detay (sunan lokasyonlar, rezervasyona bağlı odalar). */
class ServiceController extends Controller
{
    public function __construct(private readonly ServiceService $services, private readonly CurrentWebsite $website) {}

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

        return view('site.service', ['service' => $service, 'locations' => $this->services->locationsFor($service), 'rooms' => $this->services->roomsFor($service)]);
    }
}
