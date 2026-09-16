<?php

namespace App\Services;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Location;
use App\Models\Website;
use Illuminate\Support\Str;

/**
 * SEO Engine (faz 15) + Sitemap (faz 21) — v1.
 *
 * Tek kaynak: website ayarları (websites.seo_*) + içerik meta alanları.
 * Şablonlar HTML üretmez; buradan dönen yapılandırılmış veriyi basar.
 *
 * ROBOTS: website.robots_index=false -> her sayfa noindex, robots.txt
 * "Disallow: /", sitemap boş. İçerik.noindex yalnızca o sayfayı düşürür.
 * Bu bayrakları değiştirmek matriste JIT ister (seo.settings).
 */
class SeoService
{
    private const TITLE_MAX = 70;

    private const DESCRIPTION_MAX = 160;

    private const DESCRIPTION_MIN = 50;

    public function __construct(
        private readonly ContentService $contents,
        private readonly GeoService $geo,
    ) {}

    /**
     * Lokasyon sayfası head verisi (GEO): LocalBusiness + Breadcrumb şeması.
     *
     * @return array{title: string, description: string, canonical: string, robots: string, locale: string, og_type: string, json_ld: array<string, mixed>}
     */
    public function locationHead(Website $website, Location $location): array
    {
        $head = $this->head(
            $website,
            null,
            $location->path(),
            $location->name.' · '.$location->city,
            $location->geo_meta_description ?: ($location->name.' — '.$location->address_line.'. '.implode(', ', $location->tags ?? [])),
        );
        $head['json_ld'] = $this->geo->locationJsonLd($website, $location);

        return $head;
    }

    /**
     * Bir sayfanın <head> verisi.
     *
     * @return array{title: string, description: string, canonical: string, robots: string, locale: string, og_type: string, json_ld: array<string, mixed>}
     */
    public function head(Website $website, ?Content $content = null, string $path = '/', ?string $titleOverride = null, ?string $descriptionOverride = null): array
    {
        // Başlık: "<çekirdek> <son ek>". Son ek ayraçla saklanır ("— Ofisvio", "| Ofisvio");
        // baştaki boşluk burada eklenir (form girdileri kırpılır). Çekirdek yoksa yalnız site adı.
        $suffix = ' '.($website->seo_title_suffix ?: '— '.$website->name);
        $core = $titleOverride ?? ($content?->meta_title ?: $content?->title);
        $title = $core === null
            ? $website->name
            : Str::limit($core, max(20, self::TITLE_MAX - mb_strlen($suffix)), '').$suffix;

        $description = $descriptionOverride
            ?? ($content?->meta_description ?: $content?->excerpt)
            ?? $website->seo_default_description
            ?? '';

        $index = $website->robots_index && ($content === null || ! $content->noindex);

        return [
            'title' => $title,
            'description' => Str::limit(trim((string) $description), self::DESCRIPTION_MAX, ''),
            'canonical' => $website->baseUrl().($content?->path() ?? $path),
            'robots' => $index ? 'index, follow' : 'noindex, nofollow',
            'locale' => $website->seo_locale ?: 'tr_TR',
            'og_type' => $content?->kind === ContentKind::POST ? 'article' : 'website',
            'json_ld' => $this->jsonLd($website, $content, $path),
        ];
    }

    /** @return array<string, mixed> */
    private function jsonLd(Website $website, ?Content $content, string $path): array
    {
        // Organization varlığı tek yerden (GeoService): sameAs, legalName, @id.
        $organization = $this->geo->organizationNode($website);

        if ($content === null) {
            return ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $website->name, 'url' => $website->baseUrl(), 'publisher' => $organization];
        }

        $node = [
            '@context' => 'https://schema.org',
            '@type' => $content->kind === ContentKind::POST ? 'Article' : 'WebPage',
            'headline' => $content->title,
            'name' => $content->title,
            'url' => $website->baseUrl().$content->path(),
            'inLanguage' => str_replace('_', '-', $website->seo_locale ?: 'tr_TR'),
            'datePublished' => $content->published_at?->toIso8601String(),
            'dateModified' => $content->updated_at?->toIso8601String(),
            'publisher' => $organization,
        ];

        if ($content->excerpt) {
            $node['description'] = $content->excerpt;
        }

        if ($content->kind === ContentKind::POST && $content->author) {
            $node['author'] = ['@type' => 'Person', 'name' => $content->author->name];
        }

        return $node;
    }

    /** robots.txt gövdesi. */
    public function robotsTxt(Website $website): string
    {
        $lines = ['User-agent: *'];

        if (! $website->robots_index) {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Allow: /';
            foreach (['/panel', '/login', '/logout', '/forgot-password', '/reset-password', '/two-factor-challenge', '/user/'] as $private) {
                $lines[] = 'Disallow: '.$private;
            }
            $lines[] = '';
            $lines[] = 'Sitemap: '.$website->baseUrl().'/sitemap.xml';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Sitemap girdileri (faz 21). robots_index kapalıysa BOŞ döner.
     *
     * @return array<int, array{loc: string, lastmod: string|null, changefreq: string, priority: string}>
     */
    public function sitemapEntries(Website $website): array
    {
        if (! $website->robots_index) {
            return [];
        }

        $base = $website->baseUrl();
        $entries = [[
            'loc' => $base.'/',
            'lastmod' => null,
            'changefreq' => 'daily',
            'priority' => '1.0',
        ]];

        foreach ($this->contents->livePages($website) as $page) {
            if (! $page->noindex) {
                $entries[] = ['loc' => $base.$page->path(), 'lastmod' => $page->updated_at?->toAtomString(), 'changefreq' => 'monthly', 'priority' => '0.7'];
            }
        }

        // Lokasyonlar yalnızca varsayılan (Ofisvio) sitede yayınlanır.
        if ($website->is_default) {
            $locations = $this->geo->publishedLocations();
            if ($locations->isNotEmpty()) {
                $entries[] = ['loc' => $base.'/lokasyonlar', 'lastmod' => null, 'changefreq' => 'weekly', 'priority' => '0.8'];
            }
            foreach ($locations as $location) {
                $entries[] = ['loc' => $base.$location->path(), 'lastmod' => $location->updated_at?->toAtomString(), 'changefreq' => 'monthly', 'priority' => '0.7'];
            }
        }

        $posts = $this->contents->livePosts($website, 1000);

        if ($posts->isNotEmpty()) {
            $entries[] = ['loc' => $base.'/blog', 'lastmod' => $posts->first()->published_at?->toAtomString(), 'changefreq' => 'weekly', 'priority' => '0.8'];
        }

        foreach ($posts as $post) {
            if (! $post->noindex) {
                $entries[] = ['loc' => $base.$post->path(), 'lastmod' => $post->updated_at?->toAtomString(), 'changefreq' => 'monthly', 'priority' => '0.6'];
            }
        }

        return $entries;
    }

    /**
     * İçerik denetimi (seo.audit) — dış servis yok, deterministik kurallar.
     *
     * @return array<int, array{content: Content, issues: array<string>}>
     */
    public function audit(Website $website): array
    {
        $findings = [];

        $live = $this->contents->livePages($website)->merge($this->contents->livePosts($website, 1000));

        foreach ($live as $content) {
            $issues = [];
            $title = $content->meta_title ?: $content->title;
            $description = $content->meta_description ?: $content->excerpt;

            if (mb_strlen($title) > self::TITLE_MAX) {
                $issues[] = 'Başlık '.mb_strlen($title).' karakter (en fazla '.self::TITLE_MAX.').';
            }
            if ($description === null || trim($description) === '') {
                $issues[] = 'Meta açıklama yok (özet de boş).';
            } elseif (mb_strlen($description) < self::DESCRIPTION_MIN) {
                $issues[] = 'Meta açıklama kısa ('.mb_strlen($description).' < '.self::DESCRIPTION_MIN.').';
            } elseif (mb_strlen($description) > self::DESCRIPTION_MAX) {
                $issues[] = 'Meta açıklama uzun ('.mb_strlen($description).' > '.self::DESCRIPTION_MAX.').';
            }
            if ($content->noindex) {
                $issues[] = 'noindex işaretli — arama motorlarında görünmez.';
            }
            if (preg_match('/^#\s/m', (string) $content->body) === 1) {
                $issues[] = 'Gövdede H1 (#) var; sayfa başlığı zaten H1, gövdede ## ile başlayın.';
            }
            if (mb_strlen(strip_tags((string) $content->body)) < 300) {
                $issues[] = 'Gövde 300 karakterden kısa.';
            }

            if ($issues !== []) {
                $findings[] = ['content' => $content, 'issues' => $issues];
            }
        }

        return $findings;
    }
}
