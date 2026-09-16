<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestJitAccessRequest;
use App\Models\Location;
use App\Models\Website;
use App\Services\AuthorizationService;
use App\Services\ContentCache;
use App\Services\ContentService;
use App\Services\GeoService;
use App\Services\JitAccessService;
use App\Services\WebsiteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GEO / Entity yönetimi (faz 16-17, F8 v1).
 *
 *   index     geo.view      — lokasyon varlıkları + denetim özeti
 *   edit/save geo.edit      — koordinat, telefon, saatler, açıklama
 *   entity    geo.settings + JIT ('geo_entity', website id) — Organization sameAs/legalName
 *   jit       geo.view -> geo.settings için grant
 */
class GeoController extends Controller
{
    public const RESOURCE = 'geo_entity';

    public function __construct(
        private readonly GeoService $geo,
        private readonly ContentService $contents,
        private readonly WebsiteService $websites,
        private readonly ContentCache $cache,
        private readonly JitAccessService $jit,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $website = $this->contents->defaultWebsite();
        $findings = $this->geo->audit();
        $issuesByLocation = [];

        foreach ($findings as $f) {
            $issuesByLocation[$f['location']->id] = $f['issues'];
        }

        return view('panel.geo.index', [
            'website' => $website,
            'locations' => $this->geo->publishedLocations(),
            'issues' => $issuesByLocation,
            'grant' => $this->jit->hasActiveGrant($user, 'geo.settings', self::RESOURCE, $website->id),
            'canEdit' => $this->authorization->can($user, 'geo.edit'),
            'canRequestJit' => $this->authorization->can($user, 'geo.settings'),
            'defaultTtl' => JitAccessService::DEFAULT_TTL_MINUTES,
            'maxTtl' => JitAccessService::MAX_TTL_MINUTES,
        ]);
    }

    public function edit(Location $location): View
    {
        return view('panel.geo.location', ['location' => $location]);
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $validated = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'district' => ['nullable', 'string', 'max:64'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'phone' => ['nullable', 'string', 'max:32'],
            'opening_hours' => ['nullable', 'string', 'max:500'],
            'geo_description' => ['nullable', 'string', 'max:20000'],
            'geo_meta_description' => ['nullable', 'string', 'max:160'],
        ]);

        // Saatler satır satır: "Mo-Fr 08:30-19:00" (schema.org openingHours biçimi).
        $hours = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($validated['opening_hours'] ?? '')) ?: [])));

        $this->geo->updateLocation($location, [
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'district' => $validated['district'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'opening_hours' => $hours === [] ? null : $hours,
            'geo_description' => $validated['geo_description'] ?? null,
            'geo_meta_description' => $validated['geo_meta_description'] ?? null,
        ]);

        $this->cache->invalidate($this->contents->defaultWebsite());

        return redirect()->route('panel.geo.index')->with('status', $location->name.' varlık bilgileri güncellendi.');
    }

    public function entity(Request $request, Website $website): RedirectResponse
    {
        $validated = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:190'],
            'same_as' => ['nullable', 'string', 'max:2000'],
        ]);

        $sameAs = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($validated['same_as'] ?? '')) ?: [])));

        foreach ($sameAs as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
                return back()->withErrors(['same_as' => 'Her satır https:// ile başlayan geçerli bir adres olmalı: '.$url])->withInput();
            }
        }

        $this->websites->updateEntity($website, ['legal_name' => $validated['legal_name'] ?? null, 'same_as' => $sameAs === [] ? null : $sameAs]);
        $this->cache->invalidate($website);

        return redirect()->route('panel.geo.index')->with('status', 'Organizasyon varlığı güncellendi.');
    }

    public function requestJit(RequestJitAccessRequest $request, Website $website): RedirectResponse
    {
        $validated = $request->validated();

        $grantId = $this->jit->grant($request->user(), 'geo.settings', [], self::RESOURCE, $website->id, $validated['reason'], null, (int) $validated['ttl_minutes']);

        if ($grantId === null) {
            return back()->withErrors(['reason' => 'JIT erişimi açılamadı: rolünüz geo.settings taşımıyor.']);
        }

        return redirect()->route('panel.geo.index')->with('status', 'Varlık ayarları için '.$validated['ttl_minutes'].' dakikalık erişim açıldı.');
    }
}
