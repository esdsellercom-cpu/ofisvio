<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Service;
use App\Models\Website;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * GEO Engine + Entity / Knowledge Graph (faz 16-17) — v1.
 *
 * Varlık modeli:
 *   Organization (website: ad, legal_name, sameAs)  <-- parentOrganization
 *   LocalBusiness (lokasyon: adres, koordinat, telefon, saatler)
 *
 * Şemaya yalnızca VAR OLAN veri girer: koordinat yoksa "geo" düğümü basılmaz,
 * telefon yoksa "telephone" yok. Uydurma değer üretmek arama motorunda
 * güven kaybıdır (§3 ile aynı ilke). Eksikler geo.audit ile listelenir.
 */
class GeoService
{
    public function __construct(private readonly SeoSettingsService $seoSettings) {}

    /** @return Collection<int, Location> */
    public function publishedLocations(): Collection
    {
        return Location::published()->with('cover')->get();
    }

    /**
     * Panel listesi: yayında olmayanlar da (geo.publish akışı).
     *
     * @return Collection<int, Location>
     */
    public function allLocations(): Collection
    {
        return Location::query()->orderBy('city')->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Yeni şube (geo.edit): yayında DEĞİL açılır; vitrine geo.publish alır.
     *
     * @param  array{name: string, city?: string|null, region?: string|null, address_line?: string|null, badge?: string|null, price_from?: string|null, sort_order?: int|null}  $data
     */
    public function create(array $data): Location
    {
        $location = new Location($this->basics($data));
        $location->slug = $this->uniqueSlug($data['name']);
        $location->is_active = true;
        $location->is_published = false;
        $location->save();

        return $location;
    }

    /**
     * Künye alanları (geo.edit): ad, şehir, bölge, adres, rozet, fiyat metni, sıra, operasyon. Hizmetler ServiceService::syncLocation.
     * Slug DEĞİŞMEZ (dış bağlantılar ve sitemap kırılmasın).
     *
     * @param  array{name: string, city?: string|null, region?: string|null, address_line?: string|null, badge?: string|null, price_from?: string|null, sort_order?: int|null, is_active?: bool}  $data
     */
    public function updateBasics(Location $location, array $data): Location
    {
        $location->fill($this->basics($data));
        $location->is_active = (bool) ($data['is_active'] ?? $location->is_active);
        $location->save();

        return $location;
    }

    /**
     * Silme (geo.publish): yalnız vitrinde olmayan şube; talepler (leads.location_id)
     * nullOnDelete ile korunur, kayıt kalıcı silinir.
     */
    public function delete(Location $location): void
    {
        if ($location->is_published) {
            throw new DomainException('Vitrindeki şube silinemez; önce vitrinden kaldırın.');
        }

        $location->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function basics(array $data): array
    {
        return [
            'name' => trim((string) $data['name']),
            'city' => $this->blank($data['city'] ?? null),
            'region' => $this->blank($data['region'] ?? null),
            'address_line' => $this->blank($data['address_line'] ?? null),
            'badge' => $this->blank($data['badge'] ?? null),
            'price_from' => $this->blank($data['price_from'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    private function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            throw new DomainException('Şube adından geçerli bir slug üretilemedi.');
        }

        $slug = $base;
        $i = 2;

        while (Location::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * Lokasyon yayını (geo.publish): is_published bayrağı. is_active (operasyon)
     * ayrı bir karardır ve burada dokunulmaz. Vitrin, sitemap ve LocalBusiness
     * şeması yalnız yayındaki şubeleri gösterir.
     */
    public function setPublished(Location $location, bool $published): Location
    {
        $location->is_published = $published;
        $location->save();

        return $location;
    }

    public function findPublished(string $slug): ?Location
    {
        return Location::published()->where('slug', $slug)->first();
    }

    /** @return array<string, mixed> */
    public function organizationNode(Website $website): array
    {
        $s = $this->seoSettings->for($website);
        $brand = $website->brand();
        $node = [
            '@type' => (string) $s['entity.org_type'],
            '@id' => $website->baseUrl().'/#organization',
            'name' => $website->name,
            'url' => $website->baseUrl(),
        ];

        if ($website->legal_name) {
            $node['legalName'] = $website->legal_name;
        }

        // Knowledge Graph alanları (faz 44): yalnız dolu olanlar — uydurma değer yok.
        $alternate = array_values(array_filter(array_map('strval', (array) $s['entity.alternate_names'])));

        if ($alternate !== []) {
            $node['alternateName'] = count($alternate) === 1 ? $alternate[0] : $alternate;
        }

        $description = trim((string) ($s['entity.description'] !== '' ? $s['entity.description'] : $s['geo.brand_definition']));

        if ($description !== '') {
            $node['description'] = $description;
        }

        foreach (['entity.logo' => 'logo', 'entity.founding_date' => 'foundingDate', 'local.maps_url' => 'hasMap'] as $key => $property) {
            if (trim((string) $s[$key]) !== '') {
                $node[$property] = trim((string) $s[$key]);
            }
        }

        if (trim((string) $s['entity.founder']) !== '') {
            $node['founder'] = ['@type' => 'Person', 'name' => trim((string) $s['entity.founder'])];
        }

        if ($brand['phone'] !== '') {
            $node['telephone'] = $brand['phone'];
        }

        if ($brand['email'] !== '') {
            $node['email'] = $brand['email'];
        }

        if ($brand['address'] !== '') {
            $node['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $brand['address'], 'addressCountry' => (string) $s['lang.country']];
        }

        $knows = array_values(array_filter(array_map('strval', (array) $s['geo.expertise'])));

        if ($knows !== []) {
            $node['knowsAbout'] = $knows;
        }

        $areas = array_values(array_unique(array_filter(array_map('strval', array_merge((array) $s['geo.locations_served'], (array) $s['entity.areas_served'], (array) $s['local.service_cities'])))));

        if ($areas !== []) {
            $node['areaServed'] = array_map(fn (string $name) => ['@type' => 'Place', 'name' => $name], $areas);
        }

        if (trim((string) $s['geo.audience']) !== '') {
            $node['audience'] = ['@type' => 'Audience', 'audienceType' => trim((string) $s['geo.audience'])];
        }

        $sameAs = array_values(array_filter(array_map('strval', $website->same_as ?? [])));

        if (trim((string) $s['entity.wikidata_id']) !== '') {
            $sameAs[] = 'https://www.wikidata.org/wiki/'.trim((string) $s['entity.wikidata_id']);
        }

        foreach (['entity.wikipedia_url', 'entity.knowledge_panel_url', 'local.gbp_url'] as $key) {
            if (trim((string) $s[$key]) !== '') {
                $sameAs[] = trim((string) $s[$key]);
            }
        }

        $sameAs = array_values(array_unique($sameAs));

        if ($sameAs !== []) {
            $node['sameAs'] = $sameAs;
        }

        return $node;
    }

    /**
     * Lokasyon sayfası JSON-LD: LocalBusiness + BreadcrumbList (@graph).
     *
     * @return array<string, mixed>
     */
    public function locationJsonLd(Website $website, Location $location): array
    {
        $url = $website->baseUrl().$location->path();

        $business = [
            '@type' => 'LocalBusiness',
            'additionalType' => 'https://schema.org/'.$this->seoSettings->string($website, 'local.business_type'),
            '@id' => $url.'#localbusiness',
            'name' => $website->name.' '.$location->name,
            'url' => $url,
            'parentOrganization' => ['@id' => $website->baseUrl().'/#organization'],
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $location->address_line,
                'addressLocality' => $location->district ?: $location->city,
                'addressRegion' => $location->city,
                'postalCode' => $location->postal_code,
                'addressCountry' => $this->seoSettings->string($website, 'lang.country'),
            ], fn ($v) => $v !== null && $v !== ''),
        ];

        if ($location->hasCoordinates()) {
            $business['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $location->latitude, 'longitude' => $location->longitude];
        }

        if ($location->phone) {
            $business['telephone'] = $location->phone;
        }

        if (! empty($location->opening_hours)) {
            $business['openingHours'] = array_values($location->opening_hours);
        }

        // Sunulan hizmetler (faz 4): ilişkisel — Offer/Service düğümleri hizmet sayfasına bağlanır.
        $offers = $location->services->where('is_active', true)->map(fn (Service $s) => ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => $s->name, 'url' => $website->baseUrl().$s->path()]])->values()->all();

        if ($offers !== []) {
            $business['makesOffer'] = $offers;
        }

        $breadcrumb = [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => $website->name, 'item' => $website->baseUrl().'/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Lokasyonlar', 'item' => $website->baseUrl().'/lokasyonlar'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $location->name, 'item' => $url],
            ],
        ];

        return ['@context' => 'https://schema.org', '@graph' => [$this->organizationNode($website), $business, $breadcrumb]];
    }

    /**
     * Lokasyon varlık alanları (geo.edit). Yetki route'ta.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateLocation(Location $location, array $data): Location
    {
        $location->fill($data);
        $location->save();

        return $location;
    }

    /**
     * Varlık denetimi (geo.audit): şemayı zayıflatan eksikler.
     *
     * @return array<int, array{location: Location, issues: array<string>}>
     */
    public function audit(): array
    {
        $findings = [];

        foreach ($this->publishedLocations() as $location) {
            $issues = [];

            if (! $location->hasCoordinates()) {
                $issues[] = 'Koordinat yok — haritada ve yerel aramada çıkmaz.';
            }
            if (! $location->phone) {
                $issues[] = 'Telefon yok.';
            }
            if (empty($location->opening_hours)) {
                $issues[] = 'Çalışma saatleri yok.';
            }
            if (! $location->postal_code) {
                $issues[] = 'Posta kodu yok.';
            }
            if (mb_strlen((string) $location->geo_description) < 200) {
                $issues[] = 'Lokasyon açıklaması 200 karakterden kısa.';
            }

            if ($issues !== []) {
                $findings[] = ['location' => $location, 'issues' => $issues];
            }
        }

        return $findings;
    }
}
