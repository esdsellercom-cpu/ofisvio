<?php

namespace App\Services;

use App\Models\Website;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Website yönetimi (faz 10). Yetki route'ta (website.manage, global).
 *
 * Varsayılan site TEKTİR ve organizasyonsuzdur; müşteri siteleri bir
 * organizasyona bağlanır (§66). Alan adı küçük harfe indirilir ve tekil
 * tutulur — CurrentWebsite Host'u buna göre eşler.
 */
class WebsiteService
{
    /** @return Collection<int, Website> */
    public function all(): Collection
    {
        return Website::query()->with('organization')->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function find(int $id): Website
    {
        return Website::query()->findOrFail($id);
    }

    /**
     * Müşteri paneli (faz 10): yalnız organizasyonun siteleri. Tenant sınırı
     * burada çizilir; çağıran doğrulanmış organizasyon id'sini geçer.
     *
     * @return Collection<int, Website>
     */
    public function forOrganization(int $organizationId): Collection
    {
        return Website::query()->where('organization_id', $organizationId)->orderBy('name')->get();
    }

    /** Yabancı site null döner (çağıran 404 verir). */
    public function findForOrganization(int $organizationId, int $id): ?Website
    {
        return Website::query()->whereKey($id)->where('organization_id', $organizationId)->first();
    }

    /**
     * @param  array{name: string, slug?: string|null, domain?: string|null, organization_id?: int|null}  $data
     */
    public function create(array $data): Website
    {
        return Website::create([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug($data['slug'] ?? null, $data['name']),
            'domain' => $this->normalizeDomain($data['domain'] ?? null),
            'organization_id' => $data['organization_id'] ?? null,
            'is_default' => false,
        ]);
    }

    /**
     * @param  array{name: string, domain?: string|null, organization_id?: int|null}  $data
     */
    public function update(Website $website, array $data): Website
    {
        $organizationId = $data['organization_id'] ?? null;

        if ($website->is_default && $organizationId !== null) {
            throw new DomainException('Varsayılan site bir organizasyona bağlanamaz; o Ofisvio\'nun kendi vitrinidir.');
        }

        $website->fill([
            'name' => trim($data['name']),
            'domain' => $this->normalizeDomain($data['domain'] ?? null),
            'organization_id' => $website->is_default ? null : $organizationId,
        ]);
        $website->save();

        return $website;
    }

    /**
     * SEO ayarları (faz 15). Yetki route'ta: seo.settings + JIT.
     *
     * @param  array{seo_title_suffix: string|null, seo_default_description: string|null, robots_index: bool, seo_locale: string}  $data
     */
    public function updateSeo(Website $website, array $data): Website
    {
        $website->fill($data);
        $website->save();

        return $website;
    }

    /**
     * Organization varlığı (faz 17). Yetki route'ta: geo.settings + JIT.
     *
     * @param  array{legal_name: string|null, same_as: array<int, string>|null}  $data
     */
    public function updateEntity(Website $website, array $data): Website
    {
        $website->fill($data);
        $website->save();

        return $website;
    }

    private function normalizeDomain(?string $domain): ?string
    {
        $domain = strtolower(trim((string) $domain));

        return $domain === '' ? null : $domain;
    }

    private function uniqueSlug(?string $requested, string $name): string
    {
        $base = Str::slug($requested ?: $name);

        if ($base === '') {
            throw new DomainException('Addan geçerli bir slug üretilemedi.');
        }

        $slug = $base;
        $i = 2;

        while (Website::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
