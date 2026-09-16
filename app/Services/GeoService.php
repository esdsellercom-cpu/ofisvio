<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Website;
use Illuminate\Database\Eloquent\Collection;

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
    /** @return Collection<int, Location> */
    public function publishedLocations(): Collection
    {
        return Location::published()->get();
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
        $node = [
            '@type' => 'Organization',
            '@id' => $website->baseUrl().'/#organization',
            'name' => $website->name,
            'url' => $website->baseUrl(),
        ];

        if ($website->legal_name) {
            $node['legalName'] = $website->legal_name;
        }

        $sameAs = array_values(array_filter($website->same_as ?? []));

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
            'additionalType' => 'https://schema.org/CoworkingSpace',
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
                'addressCountry' => 'TR',
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

        if (! empty($location->tags)) {
            $business['makesOffer'] = array_map(fn (string $tag) => ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => $tag]], $location->tags);
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
