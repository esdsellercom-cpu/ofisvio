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
     * @param  array{name: string, slug?: string|null, domain?: string|null, organization_id?: int|null, theme?: string|null}  $data
     */
    public function create(array $data): Website
    {
        return Website::create([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug($data['slug'] ?? null, $data['name']),
            'domain' => $this->normalizeDomain($data['domain'] ?? null),
            'theme' => $this->theme($data['theme'] ?? null),
            'organization_id' => $data['organization_id'] ?? null,
            'is_default' => false,
        ]);
    }

    /**
     * @param  array{name: string, domain?: string|null, organization_id?: int|null, theme?: string|null}  $data
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
            'theme' => $this->theme($data['theme'] ?? $website->theme),
        ]);
        $website->save();

        return $website;
    }

    /**
     * Site silme (website.manage, soft delete): varsayılan site ve içeriği olan
     * site silinemez — içerik önce taşınır/silinir; alan adı boşa çıkar
     * (domain NULL, slug "-silindi-{id}"), bloklar/ayarlar kayıtla kalır.
     */
    public function delete(Website $website): void
    {
        if ($website->is_default) {
            throw new DomainException('Varsayılan site (Ofisvio vitrini) silinemez.');
        }

        if ($website->contents()->withTrashed()->exists()) {
            throw new DomainException('İçeriği olan site silinemez; önce içerikleri silin (çöp dahil).');
        }

        // Alan adı ve slug boşa çıkar: DB unique indeksleri deleted_at bilmez; silinen kayıt onları rezerve etmesin.
        $website->domain = null;
        $website->slug = $website->slug.'-silindi-'.$website->id;
        $website->save();
        $website->delete();
    }

    /** Hero görseli (website.manage): medya id ya da null (yer tutucu). Site eşleşmesi çağıranda. */
    public function updateHero(Website $website, ?int $mediaId): Website
    {
        $website->hero_media_id = $mediaId;
        $website->save();

        return $website;
    }

    /** Tema (faz 10): müşteri paneli de çağırır (content.edit, company). Bilinmeyen anahtar reddedilir. */
    public function updateTheme(Website $website, string $theme): Website
    {
        $website->theme = $this->theme($theme);
        $website->save();

        return $website;
    }

    /**
     * Sayfa dışı menü bağlantıları (faz 10). Girdi satır satır "Etiket | URL";
     * URL https://, http://, mailto:, tel: ya da site içi "/yol" olabilir.
     * Geçersiz satır DomainException — sessiz atlama menüde sürpriz bırakır.
     */
    public function updateNavLinks(Website $website, string $lines): Website
    {
        $links = [];

        foreach (preg_split('/\r?\n/', $lines) ?: [] as $i => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 2));

            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw new DomainException(($i + 1).'. satır "Etiket | URL" biçiminde olmalı.');
            }

            [$label, $url] = $parts;

            $valid = preg_match('#^(https?://[^\s]+|mailto:[^\s@]+@[^\s]+|tel:\+?[0-9 ]+|/[^\s]*)$#', $url) === 1;

            if (! $valid || mb_strlen($label) > 40) {
                throw new DomainException(($i + 1).'. satır: URL https://, mailto:, tel: ya da /yol olmalı; etiket en fazla 40 karakter.');
            }

            $links[] = ['label' => $label, 'url' => $url];
        }

        if (count($links) > 8) {
            throw new DomainException('En fazla 8 dış bağlantı.');
        }

        $website->nav_links = $links === [] ? null : $links;
        $website->save();

        return $website;
    }

    private function theme(?string $theme): string
    {
        $themes = array_keys((array) config('ofisvio.themes'));

        if ($theme === null || $theme === '') {
            return 'kum';
        }

        if (! in_array($theme, $themes, true)) {
            throw new DomainException('Bilinmeyen tema: '.$theme);
        }

        return $theme;
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
     * Site genel ayarları (faz 29): iletişim/kimlik. Personel (website.manage)
     * ve müşteri (content.edit, kendi sitesi) aynı yolu kullanır.
     *
     * @param  array{contact_phone?: string|null, contact_email?: string|null, tagline?: string|null, address?: string|null, legal_name?: string|null}  $data
     */
    public function updateSettings(Website $website, array $data): Website
    {
        $website->fill([
            'contact_phone' => $this->blankToNull($data['contact_phone'] ?? null),
            'contact_email' => $this->blankToNull($data['contact_email'] ?? null),
            'tagline' => $this->blankToNull($data['tagline'] ?? null),
            'address' => $this->blankToNull($data['address'] ?? null),
            'legal_name' => $this->blankToNull($data['legal_name'] ?? $website->legal_name),
        ]);
        $website->save();

        return $website;
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Önbellek ayarları (faz 12-14, cache.settings + JIT). NULL = kod varsayılanı.
     *
     * @param  array{cache_ttl_seconds: int|null, http_max_age: int|null, http_s_maxage: int|null}  $data
     */
    public function updateCacheSettings(Website $website, array $data): Website
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
