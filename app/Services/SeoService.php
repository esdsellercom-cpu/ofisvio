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
        private readonly SiteBlockService $blocks,
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
     * @return array{title: string, description: string, canonical: string, robots: string, locale: string, og_type: string, og_image: string|null, json_ld: array<string, mixed>}
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
            'og_image' => $content?->cover_url ?: ($website->hero_media_id !== null ? $website->hero?->url() : null),
            'json_ld' => $this->jsonLd($website, $content, $path),
        ];
    }

    /** @return array<string, mixed> */
    private function jsonLd(Website $website, ?Content $content, string $path): array
    {
        // Organization varlığı tek yerden (GeoService): sameAs, legalName, @id.
        $organization = $this->geo->organizationNode($website);

        if ($content === null) {
            $site = ['@type' => 'WebSite', 'name' => $website->name, 'url' => $website->baseUrl(), 'publisher' => $organization];

            // Ofisvio ana sayfası: çözümler Service düğümü olarak (faz 17) — veri vitrin bloklarından, uydurma yok.
            if ($path === '/' && $website->is_default) {
                $services = $this->serviceNodes($website);

                if ($services !== []) {
                    return ['@context' => 'https://schema.org', '@graph' => array_merge([$site], $services)];
                }
            }

            return ['@context' => 'https://schema.org', ...$site];
        }

        $node = [
            '@type' => $content->kind === ContentKind::POST ? 'Article' : 'WebPage',
            '@id' => $website->baseUrl().$content->path().'#main',
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

        // @graph: sayfa + BreadcrumbList (faz 15) + varsa FAQPage (faz 17).
        $graph = [$node, $this->breadcrumb($website, $content)];
        $faq = $this->faqNode($website, $content);

        if ($faq !== null) {
            $graph[] = $faq;
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /**
     * BreadcrumbList: Ana sayfa → [Günlük → Kategori] → başlık.
     *
     * @return array<string, mixed>
     */
    private function breadcrumb(Website $website, Content $content): array
    {
        $base = $website->baseUrl();
        $items = [['name' => $website->name, 'item' => $base.'/']];

        if ($content->kind === ContentKind::POST) {
            $items[] = ['name' => 'Yazılar', 'item' => $base.'/blog'];

            if ($content->category) {
                $items[] = ['name' => $content->category, 'item' => $base.'/blog/kategori/'.Str::slug($content->category)];
            }
        }

        if ($content->kind === ContentKind::PAGE && $content->parent_slug) {
            $parent = $content->parent;
            $items[] = ['name' => $parent !== null ? $parent->title : $content->parent_slug, 'item' => $base.'/'.$content->parent_slug];
        }

        $items[] = ['name' => $content->title, 'item' => $base.$content->path()];

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $i, int $idx) => ['@type' => 'ListItem', 'position' => $idx + 1, 'name' => $i['name'], 'item' => $i['item']], $items, array_keys($items)),
        ];
    }

    /**
     * FAQPage: gövdede "## Soru?" başlıkları ve altındaki paragraflar. En az iki
     * soru-cevap çifti yoksa düğüm üretilmez — içerikte olmayan SSS şemaya girmez.
     *
     * @return array<string, mixed>|null
     */
    private function faqNode(Website $website, Content $content): ?array
    {
        $pairs = self::faqPairs((string) $content->body);

        if (count($pairs) < 2) {
            return null;
        }

        return [
            '@type' => 'FAQPage',
            '@id' => $website->baseUrl().$content->path().'#faq',
            'mainEntity' => array_map(fn (array $p) => [
                '@type' => 'Question',
                'name' => $p['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $p['a']],
            ], $pairs),
        ];
    }

    /**
     * Markdown'dan soru-cevap çiftleri: "## …?" başlığı + sonraki başlığa kadar düz metin.
     *
     * @return array<int, array{q: string, a: string}>
     */
    public static function faqPairs(string $markdown): array
    {
        // Önce bölümle: [soru, cevap satırları]; sonra boş cevapları ele.
        $sections = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $markdown) ?: [] as $line) {
            if (preg_match('/^#{2,3}\s+(.+\?)\s*$/u', $line, $m) === 1) {
                $current = count($sections);
                $sections[$current] = ['q' => trim($m[1]), 'lines' => []];

                continue;
            }

            if (preg_match('/^#{1,6}\s/', $line) === 1) {
                $current = null; // soru olmayan başlık: cevap biter

                continue;
            }

            if ($current !== null) {
                $sections[$current]['lines'][] = $line;
            }
        }

        $pairs = [];

        foreach ($sections as $section) {
            $text = trim((string) preg_replace('/\s+/', ' ', strip_tags(implode(' ', $section['lines']))));

            if ($text !== '') {
                $pairs[] = ['q' => $section['q'], 'a' => $text];
            }
        }

        return $pairs;
    }

    /**
     * Service düğümleri (faz 17): vitrin "çözümler" bloğundan; fiyat metni
     * ("₺790/ay'dan") sayıya çevrilmez — Offer yalnız description taşır.
     *
     * @return array<int, array<string, mixed>>
     */
    private function serviceNodes(Website $website): array
    {
        $solutions = (array) ($this->blocks->all($website)['solutions'] ?? []);
        $nodes = [];

        foreach ($solutions as $s) {
            if (! is_array($s) || empty($s['title'])) {
                continue;
            }

            $nodes[] = [
                '@type' => 'Service',
                'name' => (string) $s['title'],
                'description' => (string) ($s['desc'] ?? ''),
                'serviceType' => (string) $s['title'],
                'provider' => ['@id' => $website->baseUrl().'/#organization'],
                'areaServed' => ['@type' => 'Country', 'name' => 'Türkiye'],
                'offers' => ['@type' => 'Offer', 'description' => (string) ($s['price'] ?? ''), 'priceCurrency' => 'TRY'],
            ];
        }

        return $nodes;
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

        // Kategori sayfaları (faz 18): yalnız yayındaki yazılardan türeyenler.
        foreach (array_keys($this->contents->categories($website)) as $categorySlug) {
            $entries[] = ['loc' => $base.'/blog/kategori/'.$categorySlug, 'lastmod' => null, 'changefreq' => 'weekly', 'priority' => '0.5'];
        }

        foreach (array_keys($this->contents->tags($website)) as $tagSlug) {
            $entries[] = ['loc' => $base.'/blog/etiket/'.$tagSlug, 'lastmod' => null, 'changefreq' => 'weekly', 'priority' => '0.4'];
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
