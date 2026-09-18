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
use App\Services\RedirectService;
use App\Services\ServiceService;
use App\Services\WebsiteService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GEO / Entity yönetimi (faz 16-17, F8 v1).
 *
 *   index     geo.view      — lokasyon varlıkları + denetim özeti
 *   create/store geo.edit   — yeni şube (yayında değil)
 *   edit/save geo.edit      — koordinat, telefon, saatler, açıklama; künye (ad, şehir, adres, etiket, fiyat metni)
 *   destroy   geo.publish   — silme (yalnız vitrinde olmayan)
 *   publish   geo.publish   — şube vitrine alınır / kaldırılır (is_published)
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
        private readonly RedirectService $redirects,
        private readonly JitAccessService $jit,
        private readonly ServiceService $services,
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
            'locations' => $this->geo->allLocations(),
            'canPublish' => $this->authorization->can($user, 'geo.publish'),
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
        return view('panel.geo.location', ['location' => $location->load('services'), 'allServices' => $this->services->all()]);
    }

    public function create(): View
    {
        return view('panel.geo.create', ['allServices' => $this->services->all()]);
    }

    public const BASICS_RULES = [
        'name' => ['required', 'string', 'min:2', 'max:120'],
        'city' => ['nullable', 'string', 'max:64'],
        'region' => ['nullable', 'string', 'max:64'],
        'address_line' => ['nullable', 'string', 'max:255'],
        'badge' => ['nullable', 'string', 'max:120'],
        'services' => ['nullable', 'array', 'max:50'], // var olan hizmet id'leri (Hizmetler modülü)
        'services.*' => ['integer'],
        'price_from' => ['nullable', 'string', 'max:48'],
        'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        'is_active' => ['sometimes', 'boolean'],
        'maintenance_until' => ['nullable', 'date_format:Y-m-d'],
        'maintenance_note' => ['nullable', 'string', 'max:200', 'required_with:maintenance_until'],
    ];

    /** geo.edit: yeni şube (yayında değil). */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(self::BASICS_RULES);

        try {
            $location = $this->geo->create($this->basicsPayload($validated));
            $this->services->syncLocation($request->user(), $location, array_map('intval', (array) ($validated['services'] ?? [])));
        } catch (DomainException $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.geo.edit', $location)->with('status', $location->name.' açıldı (vitrinde değil); varlık alanlarını doldurup "Vitrine al" ile yayınlayın.');
    }

    /** geo.edit: künye alanları. */
    public function updateBasics(Request $request, Location $location): RedirectResponse
    {
        $validated = $request->validate(self::BASICS_RULES);

        try {
            $this->geo->updateBasics($location, $this->basicsPayload($validated) + ['is_active' => (bool) ($validated['is_active'] ?? false)]);
        } catch (DomainException $e) {
            return back()->withErrors(['maintenance_until' => $e->getMessage()])->withInput();
        }
        // Hizmetler: yalnız seçim (Hizmetler modülünde var olanlar); lokasyon ekranı hizmet oluşturmaz.
        $this->services->syncLocation($request->user(), $location, array_map('intval', (array) ($validated['services'] ?? [])));
        $this->cache->invalidate($this->contents->defaultWebsite());

        return redirect()->route('panel.geo.edit', $location)->with('status', $location->name.' künyesi güncellendi.');
    }

    /** geo.publish: silme — yalnız vitrinde olmayan şube. */
    /** Silmeden önce (faz 54): /lokasyon/{slug} için yönlendirme seçimi. */
    public function confirmDelete(Location $location): View
    {
        return view('panel.content.delete', [
            'content' => null,
            'path' => $location->path(),
            'wasLive' => $location->is_published,
            'suggestions' => $this->redirects->suggest($this->contents->defaultWebsite(), $location->path(), RedirectService::snapshotOf($location)),
            'action' => route('panel.geo.destroy', $location),
            'cancel' => route('panel.geo.edit', $location),
            'label' => $location->name,
        ]);
    }

    public function destroy(Request $request, Location $location): RedirectResponse
    {
        try {
            $this->geo->delete($location, self::redirectChoice($request));
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('panel.geo.index')->with('status', $location->name.' silindi.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{name: string, city: string|null, region: string|null, address_line: string|null, badge: string|null, price_from: string|null, sort_order: int}
     */
    private function basicsPayload(array $validated): array
    {
        return [
            'name' => (string) $validated['name'],
            'city' => $validated['city'] ?? null,
            'region' => $validated['region'] ?? null,
            'address_line' => $validated['address_line'] ?? null,
            'badge' => $validated['badge'] ?? null,
            'price_from' => $validated['price_from'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'maintenance_until' => $validated['maintenance_until'] ?? null,
            'maintenance_note' => ! empty($validated['maintenance_until']) ? ($validated['maintenance_note'] ?? null) : null,
        ];
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
            'transport' => ['nullable', 'string', 'max:500'],
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
            'transport' => $validated['transport'] ?? null,
            'geo_description' => $validated['geo_description'] ?? null,
            'geo_meta_description' => $validated['geo_meta_description'] ?? null,
        ]);

        $this->cache->invalidate($this->contents->defaultWebsite());

        return redirect()->route('panel.geo.index')->with('status', $location->name.' varlık bilgileri güncellendi.');
    }

    /** geo.publish: şubeyi vitrine al / vitrinden kaldır. */
    public function publish(Request $request, Location $location): RedirectResponse
    {
        $validated = $request->validate(['is_published' => ['required', 'boolean']]);

        $this->geo->setPublished($location, (bool) $validated['is_published']);
        $this->cache->invalidate($this->contents->defaultWebsite());

        return redirect()->route('panel.geo.index')->with('status', $location->name.($validated['is_published'] ? ' vitrine alındı.' : ' vitrinden kaldırıldı.'));
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
