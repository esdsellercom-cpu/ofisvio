<?php

namespace App\Services;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Location;
use App\Models\Media;
use App\Models\Website;
use App\Seo\SeoSettingsRegistry;
use Illuminate\Support\Str;

/**
 * SEO Engine (faz 15) + Sitemap (faz 21) + gelişmiş ayarlar (faz 44).
 *
 * Tek kaynak: website ayarları (websites.seo_* yalın sütunlar + seo_settings JSON) + içerik meta alanları.
 * Şablonlar HTML üretmez; buradan dönen yapılandırılmış veriyi basar.
 *
 * ROBOTS: website.robots_index=false -> her sayfa noindex, robots.txt "Disallow: /", sitemap boş.
 * İçerik.noindex yalnızca o sayfayı düşürür. Gelişmiş ayarlar (SeoSettingsRegistry) bu bayrağın
 * ÜSTÜNE biner: hiçbir seçenek kapalı siteyi indekse sokmaz. Bayrak değişikliği JIT ister (seo.settings).
 */
class SeoService
{
    private const TITLE_MAX = 70;

    private const DESCRIPTION_MAX = 160;

    private const DESCRIPTION_MIN = 50;

    /** Vitrin sabit yolları (kırık bağlantı/yetim denetimi için bilinen hedefler). */
    private const STATIC_PATHS = ['/', '/blog', '/lokasyonlar', '/cozumler', '/etkinlikler', '/franchise', '/rezervasyon', '/site-haritasi', '/llms.txt', '/sitemap.xml', '/robots.txt'];

    public function __construct(
        private readonly ContentService $contents,
        private readonly GeoService $geo,
        private readonly ServiceService $services,
        private readonly EventService $events,
        private readonly SeoSettingsService $settings,
        private readonly UrlHistoryService $urls,
        private readonly SiteBlockService $blocks,
    ) {}

    /**
     * Lokasyon sayfası head verisi (GEO): LocalBusiness + Breadcrumb şeması.
     *
     * @return array<string, mixed>
     */
    public function locationHead(Website $website, Location $location): array
    {
        $head = $this->head(
            $website,
            null,
            $location->path(),
            $location->name.' · '.$location->city,
            $location->geo_meta_description ?: ($location->name.' — '.$location->address_line.'. '.implode(', ', $location->serviceNames())),
            'location',
        );
        $jsonLd = $this->geo->locationJsonLd($website, $location);

        // Kapak görseli (medya kütüphanesi) paylaşım görseli ve LocalBusiness image olur.
        if ($location->cover_media_id !== null && $location->cover !== null) {
            $head['og_image'] = $location->cover->absoluteUrlFor(1600);
            $jsonLd['image'] = $location->cover->absoluteUrl();

            if ($head['twitter'] !== null && $head['twitter']['image'] === null) {
                $head['twitter']['image'] = $head['og_image'];
            }
        }

        $head['json_ld'] = $this->finishJsonLd($website, $jsonLd, $location->path());

        return $head;
    }

    /**
     * Bir sayfanın <head> verisi. $pageType: home | content | listing | location | page —
     * liste sayfaları için noindex ayarı ve içeriksiz sayfa OG varsayılanları bununla seçilir.
     *
     * @return array<string, mixed>
     */
    public function head(Website $website, ?Content $content = null, string $path = '/', ?string $titleOverride = null, ?string $descriptionOverride = null, string $pageType = 'page'): array
    {
        $s = $this->settings->for($website);
        $pageType = $content !== null ? 'content' : ($path === '/' && $pageType === 'page' ? 'home' : $pageType);

        // Başlık: "<çekirdek> <son ek>" ya da şablon ({title} {site} {suffix}). Son ek ayraçla saklanır
        // ("— Ofisvio", "| Ofisvio"); baştaki boşluk burada eklenir (form girdileri kırpılır).
        $suffixRaw = (string) ($website->seo_title_suffix ?: '— '.$website->name);
        $suffix = ' '.$suffixRaw;
        $core = $titleOverride ?? ($content?->meta_title ?: $content?->title);
        $template = trim((string) $s['meta.title_template']);

        if ($template !== '') {
            $title = Str::limit(trim(strtr($template, ['{title}' => $core ?? $website->name, '{site}' => $website->name, '{suffix}' => $suffixRaw])), self::TITLE_MAX + 20, '');
        } else {
            $title = $core === null
                ? $website->name
                : Str::limit($core, max(20, self::TITLE_MAX - mb_strlen($suffix)), '').$suffix;
        }

        $description = $descriptionOverride
            ?? ($content?->meta_description ?: $content?->excerpt)
            ?? $website->seo_default_description
            ?? '';
        $description = trim((string) $description);
        $descriptionTemplate = trim((string) $s['meta.description_template']);

        if ($descriptionTemplate !== '' && $description !== '') {
            $description = trim(strtr($descriptionTemplate, ['{description}' => $description, '{site}' => $website->name]));
        }

        $description = Str::limit($description, self::DESCRIPTION_MAX, '');

        $index = $website->robots_index && ($content === null || ! $content->noindex) && ! ($pageType === 'listing' && $s['crawl.noindex_listings']);
        // Sayfa düzeyi robots (faz 48): "noindex, …" seçildiyse indeks kapanır; nofollow ayrıca yönergeye eklenir.
        $contentRobots = $content !== null ? (string) ($content->robots ?? '') : '';
        $index = $index && ! str_starts_with($contentRobots, 'noindex');
        $canonicalPath = $content?->path() ?? $path;
        // Paylaşım görselleri mutlak adres ister (Media adresleri bağıldır; bkz. Media::absolute).
        $pageImage = $content?->cover_url ?: ($website->hero_media_id !== null ? $website->hero?->url() : null);
        $pageImage = $pageImage !== null && $pageImage !== '' ? Media::absolute($pageImage) : null;
        $ogImage = $content !== null && $content->og_media_id !== null && $content->ogImage !== null ? $content->ogImage->absoluteUrl() : ($pageImage ?: ($s['meta.og_image'] !== '' ? $s['meta.og_image'] : null));
        $ogTitle = $content !== null && trim((string) $content->og_title) !== '' ? trim((string) $content->og_title) : ($content === null && $s['meta.og_title'] !== '' ? $s['meta.og_title'] : $title);
        $ogDescription = $content !== null && trim((string) $content->og_description) !== '' ? trim((string) $content->og_description) : ($content === null && $s['meta.og_description'] !== '' ? $s['meta.og_description'] : $description);
        $contentCanonical = $content !== null ? trim((string) ($content->canonical_url ?? '')) : '';

        // Canonical yönlendirilen bir yola bakıyorsa kanonik = yönlendirmenin hedefi (faz 54; zincir izlenir).
        if ($contentCanonical !== '' && str_starts_with($contentCanonical, '/') && ($hit = $this->urls->resolve($website, $contentCanonical)) !== null && str_starts_with($hit['to'], '/')) {
            $contentCanonical = $hit['to'];
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $contentCanonical !== '' ? (str_starts_with($contentCanonical, '/') ? $this->canonicalBase($website).$contentCanonical : $contentCanonical) : ($s['url.canonical_auto'] ? $this->canonicalBase($website).$canonicalPath : null),
            'robots' => $this->robotsDirective($s, $index, str_ends_with($contentRobots, 'nofollow')),
            'locale' => $website->seo_locale ?: 'tr_TR',
            'og_type' => $content?->kind === ContentKind::POST ? 'article' : 'website',
            'og_image' => $ogImage,
            'og' => (bool) $s['meta.og_enabled'],
            'og_title' => $ogTitle,
            'og_description' => $ogDescription,
            'keywords' => trim((string) $s['meta.keywords']),
            'twitter' => $s['meta.twitter_card'] === 'none' ? null : [
                'card' => $s['meta.twitter_card'],
                'site' => $s['meta.twitter_site'] !== '' ? '@'.ltrim($s['meta.twitter_site'], '@') : null,
                'title' => $content === null && $s['meta.twitter_title'] !== '' ? $s['meta.twitter_title'] : $ogTitle,
                'description' => $content === null && $s['meta.twitter_description'] !== '' ? $s['meta.twitter_description'] : $ogDescription,
                'image' => $pageImage ?: ($s['meta.twitter_image'] !== '' ? $s['meta.twitter_image'] : $ogImage),
            ],
            'hreflang' => $this->hreflang($website, $s, $canonicalPath),
            'meta' => $this->extraMeta($s),
            'links' => $this->resourceHints($s),
            'head_code' => (string) $s['dev.head_code'],
            'body_start' => (string) $s['dev.body_start'],
            'body_end' => (string) $s['dev.body_end'],
            'ga4_id' => (string) $s['verify.ga4_id'],
            'gtm_id' => (string) $s['verify.gtm_id'],
            'json_ld' => $this->finishJsonLd($website, $this->jsonLd($website, $content, $path), $canonicalPath),
        ];
    }

    /** Canonical/sitemap kök adresi: canonical alan adı ayarı varsa o, yoksa sitenin adresi. */
    public function canonicalBase(Website $website): string
    {
        $host = $this->settings->string($website, 'url.canonical_host');

        return $host !== '' ? 'https://'.$host : $website->baseUrl();
    }

    /** @param  array<string, mixed>  $s */
    private function robotsDirective(array $s, bool $index, bool $nofollow = false): string
    {
        if (! $index) {
            $parts = ['noindex', 'nofollow'];
        } else {
            $parts = ['index', $s['crawl.nofollow_default'] || $nofollow ? 'nofollow' : 'follow'];
        }

        if ($s['crawl.noarchive']) {
            $parts[] = 'noarchive';
        }

        if ($s['crawl.nosnippet']) {
            $parts[] = 'nosnippet';
        }

        if ((int) $s['crawl.max_snippet'] >= 0) {
            $parts[] = 'max-snippet:'.(int) $s['crawl.max_snippet'];
        }

        if ($s['crawl.max_image_preview'] !== 'large') {
            $parts[] = 'max-image-preview:'.$s['crawl.max_image_preview'];
        }

        if ((int) $s['crawl.max_video_preview'] >= 0) {
            $parts[] = 'max-video-preview:'.(int) $s['crawl.max_video_preview'];
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<int, array{hreflang: string, href: string}>
     */
    private function hreflang(Website $website, array $s, string $path): array
    {
        if (! $s['lang.hreflang_enabled']) {
            return [];
        }

        $out = [['hreflang' => str_replace('_', '-', (string) ($website->seo_locale ?: 'tr_TR')), 'href' => $this->canonicalBase($website).$path]];

        foreach ((array) $s['lang.alternates'] as $row) {
            if (is_array($row) && ($row['hreflang'] ?? '') !== '' && ($row['url'] ?? '') !== '') {
                $out[] = ['hreflang' => (string) $row['hreflang'], 'href' => rtrim((string) $row['url'], '/').$path];
            }
        }

        $out[] = ['hreflang' => 'x-default', 'href' => rtrim($s['lang.x_default'] !== '' ? (string) $s['lang.x_default'] : $this->canonicalBase($website), '/').$path];

        return $out;
    }

    /**
     * Doğrulama + özel meta etiketleri (ad=içerik).
     *
     * @param  array<string, mixed>  $s
     * @return array<int, array{name: string, content: string}>
     */
    private function extraMeta(array $s): array
    {
        $out = [];

        foreach (['verify.google' => 'google-site-verification', 'verify.bing' => 'msvalidate.01', 'verify.yandex' => 'yandex-verification'] as $key => $name) {
            if (trim((string) $s[$key]) !== '') {
                $out[] = ['name' => $name, 'content' => trim((string) $s[$key])];
            }
        }

        foreach (array_merge((array) $s['verify.extra_meta'], (array) $s['dev.custom_meta']) as $line) {
            [$name, $content] = array_pad(explode('=', (string) $line, 2), 2, '');

            if (trim($name) !== '' && trim($content) !== '') {
                $out[] = ['name' => trim($name), 'content' => trim($content)];
            }
        }

        return $out;
    }

    /**
     * Kaynak ipuçları: preload (adres|tür), dns-prefetch, preconnect.
     *
     * @param  array<string, mixed>  $s
     * @return array<int, array{rel: string, href: string, as: string|null}>
     */
    private function resourceHints(array $s): array
    {
        $out = [];

        foreach ((array) $s['dev.preload'] as $line) {
            [$href, $as] = array_pad(explode('|', (string) $line, 2), 2, '');

            if (trim($href) !== '' && in_array(trim($as), ['font', 'image', 'style', 'script', 'fetch'], true)) {
                $out[] = ['rel' => 'preload', 'href' => trim($href), 'as' => trim($as)];
            }
        }

        foreach (['dev.dns_prefetch' => 'dns-prefetch', 'dev.preconnect' => 'preconnect'] as $key => $rel) {
            foreach ((array) $s[$key] as $origin) {
                if (preg_match('#^https://[^\s/]+$#', trim((string) $origin)) === 1) {
                    $out[] = ['rel' => $rel, 'href' => trim((string) $origin), 'as' => null];
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function jsonLd(Website $website, ?Content $content, string $path): array
    {
        // Organization varlığı tek yerden (GeoService): sameAs, legalName, @id + Knowledge Graph alanları.
        $organization = $this->geo->organizationNode($website);

        if ($content === null) {
            $site = ['@type' => 'WebSite', 'name' => $website->name, 'url' => $website->baseUrl(), 'publisher' => $organization];

            if ($path === '/') {
                $graph = [$site];

                // Ofisvio ana sayfası: çözümler Service düğümü olarak (faz 17) — veri vitrin bloklarından, uydurma yok.
                if ($website->is_default) {
                    $graph = array_merge($graph, $this->serviceNodes($website));

                    // Tek lokasyon modu (faz 55): şube ana sayfada öne çıktığı için LocalBusiness (adres, telefon, saat, koordinat) da burada.
                    $single = $this->blocks->singleLocation();

                    if ($single !== null) {
                        $graph[] = $this->geo->localBusinessNode($website, $single->loadMissing('services'));
                    }
                }

                // GEO SSS (faz 44): panelde tanımlı soru-cevaplar ana sayfada FAQPage (≥ 2 çift).
                $faq = $this->settingsFaqNode($website);

                if ($faq !== null) {
                    $graph[] = $faq;
                }

                return count($graph) === 1 ? ['@context' => 'https://schema.org', ...$site] : ['@context' => 'https://schema.org', '@graph' => $graph];
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

        // @graph: sayfa + BreadcrumbList (faz 15) + varsa FAQPage (faz 17) + sayfa düzeyi seçimler (faz 48).
        $graph = [$node, $this->breadcrumb($website, $content)];
        $faq = $this->faqNode($website, $content) ?? $this->geoFaqNode($website, $content);

        if ($faq !== null) {
            $graph[] = $faq;
        }

        $selected = (array) ($content->schema_types ?? []);

        if (in_array('Service', $selected, true)) {
            $graph[] = ['@type' => 'Service', '@id' => $website->baseUrl().$content->path().'#service', 'name' => $content->title, 'description' => (string) ($content->excerpt ?? ''), 'provider' => ['@id' => $website->baseUrl().'/#organization'], 'url' => $website->baseUrl().$content->path()];
        }

        if (in_array('LocalBusiness', $selected, true)) {
            $graph[] = ['@type' => 'LocalBusiness', '@id' => $website->baseUrl().'/#localbusiness'] + array_diff_key($organization, ['@type' => 1, '@id' => 1]);
        }

        if (in_array('Organization', $selected, true)) {
            $graph[] = $organization;
        }

        if ($selected !== []) {
            // Seçilmeyen sayfa türleri düşer (WebPage/Article, BreadcrumbList, FAQPage); Organization publisher olarak kalır.
            $graph = array_values(array_filter($graph, fn (array $n) => ! in_array($n['@type'], ['WebPage', 'Article', 'BreadcrumbList', 'FAQPage'], true) || in_array($n['@type'], $selected, true)));
        }

        $custom = json_decode((string) ($content->schema_custom ?? ''), true);

        if (is_array($custom)) {
            foreach (array_is_list($custom) ? $custom : [$custom] as $customNode) {
                if (is_array($customNode)) {
                    $graph[] = $customNode;
                }
            }
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /**
     * Şema son işlem (faz 44): JSON-LD kapalıysa boş; etkin olmayan türler @graph'tan düşer;
     * site geneli ve yola bağlı özel JSON-LD eklenir.
     *
     * @param  array<string, mixed>  $jsonLd
     * @return array<string, mixed>
     */
    private function finishJsonLd(Website $website, array $jsonLd, string $path): array
    {
        $s = $this->settings->for($website);

        if (! $s['schema.enabled']) {
            return [];
        }

        $enabled = (array) $s['schema.types'];
        $graph = isset($jsonLd['@graph']) && is_array($jsonLd['@graph']) ? $jsonLd['@graph'] : [array_diff_key($jsonLd, ['@context' => 1])];
        $graph = array_values(array_filter(array_map(fn (array $node) => $this->filterNode($node, $enabled), $graph)));

        foreach ($this->customNodes($s, $path) as $custom) {
            $graph[] = $custom;
        }

        if ($graph === []) {
            return [];
        }

        return count($graph) === 1 ? ['@context' => 'https://schema.org', ...$graph[0]] : ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /**
     * Düğüm türü etkin değilse null; iç içe publisher/parentOrganization da aynı süzgeçten geçer.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $enabled
     * @return array<string, mixed>|null
     */
    private function filterNode(array $node, array $enabled): ?array
    {
        $type = (string) ($node['@type'] ?? '');
        $family = match (true) {
            $type === 'Article', $type === 'WebPage', $type === 'WebSite', $type === 'BreadcrumbList', $type === 'FAQPage', $type === 'Service', $type === 'LocalBusiness', $type === 'Event' => $type,
            in_array($type, ['Organization', 'Corporation', 'ProfessionalService', 'RealEstateAgent'], true) => 'Organization',
            default => null,
        };

        if ($family !== null && ! in_array($family, $enabled, true)) {
            return null;
        }

        if (! in_array('Organization', $enabled, true)) {
            unset($node['publisher'], $node['parentOrganization'], $node['provider']);
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<int, array<string, mixed>>
     */
    private function customNodes(array $s, string $path): array
    {
        $out = [];
        $sitewide = json_decode((string) $s['schema.custom_sitewide'], true);

        if (is_array($sitewide)) {
            $out = array_merge($out, array_is_list($sitewide) ? array_filter($sitewide, 'is_array') : [$sitewide]);
        }

        foreach ((array) $s['schema.custom_by_path'] as $row) {
            if (is_array($row) && ($row['path'] ?? null) === $path) {
                $decoded = json_decode((string) ($row['json'] ?? ''), true);

                if (is_array($decoded)) {
                    $out = array_merge($out, array_is_list($decoded) ? array_filter($decoded, 'is_array') : [$decoded]);
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function settingsFaqNode(Website $website): ?array
    {
        $pairs = array_values(array_filter($this->settings->rows($website, 'geo.faq'), fn (array $r) => ($r['q'] ?? '') !== '' && ($r['a'] ?? '') !== ''));

        if (count($pairs) < 2) {
            return null;
        }

        return [
            '@type' => 'FAQPage',
            '@id' => $website->baseUrl().'/#faq',
            'mainEntity' => array_map(fn (array $p) => ['@type' => 'Question', 'name' => $p['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $p['a']]], $pairs),
        ];
    }

    /**
     * BreadcrumbList: Ana sayfa → [Günlük → Kategori] → başlık.
     *
     * @return array<string, mixed>
     */
    private function breadcrumb(Website $website, Content $content): array
    {
        $items = $this->breadcrumbItems($website, $content);

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $i, int $idx) => ['@type' => 'ListItem', 'position' => $idx + 1, 'name' => $i['name'], 'item' => $i['item']], $items, array_keys($items)),
        ];
    }

    /**
     * Görünür breadcrumb ve şema aynı listeden (faz 44).
     *
     * @return array<int, array{name: string, item: string}>
     */
    public function breadcrumbItems(Website $website, Content $content): array
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

        return $items;
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

    /** GEO sekmesindeki SSS önerileri (faz 48): gövdede FAQ yoksa, yöneticinin kaydettiği çiftler (≥ 2) şemaya girer. */
    private function geoFaqNode(Website $website, Content $content): ?array
    {
        $pairs = array_values(array_filter((array) (($content->geo ?? [])['faq'] ?? []), fn ($p) => is_array($p) && trim((string) ($p['q'] ?? '')) !== '' && trim((string) ($p['a'] ?? '')) !== ''));

        if (count($pairs) < 2) {
            return null;
        }

        return [
            '@type' => 'FAQPage',
            '@id' => $website->baseUrl().$content->path().'#faq',
            'mainEntity' => array_map(fn (array $p) => ['@type' => 'Question', 'name' => $p['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $p['a']]], $pairs),
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
     * Service düğümleri (faz 17 → faz 4): Hizmetler modülünden; fiyat metni
     * ("₺790/ay'dan") sayıya çevrilmez — Offer yalnız description taşır.
     *
     * @return array<int, array<string, mixed>>
     */
    private function serviceNodes(Website $website): array
    {
        $nodes = [];

        foreach ($this->services->active($website) as $service) {
            $nodes[] = [
                '@type' => 'Service',
                'name' => $service->name,
                'url' => $website->baseUrl().$service->path(),
                'description' => (string) ($service->summary ?? ''),
                'serviceType' => $service->name,
                'provider' => ['@id' => $website->baseUrl().'/#organization'],
                'areaServed' => ['@type' => 'Country', 'name' => 'Türkiye'],
                'offers' => ['@type' => 'Offer', 'description' => (string) ($service->price_text ?? ''), 'priceCurrency' => 'TRY'],
            ];
        }

        return $nodes;
    }

    /** robots.txt gövdesi: bayrak + ek satırlar + AI bot kuralları; ya da özel metin. */
    public function robotsTxt(Website $website): string
    {
        $s = $this->settings->for($website);

        if ($s['crawl.robots_mode'] === 'custom' && trim((string) $s['crawl.robots_custom']) !== '') {
            $custom = rtrim(str_replace("\r\n", "\n", (string) $s['crawl.robots_custom']))."\n";

            return $website->robots_index ? $custom : "User-agent: *\nDisallow: /\n\n".$custom;
        }

        $lines = ['User-agent: *'];

        if (! $website->robots_index) {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Allow: /';
            foreach (['/panel', '/login', '/logout', '/forgot-password', '/reset-password', '/two-factor-challenge', '/user/'] as $private) {
                $lines[] = 'Disallow: '.$private;
            }

            foreach (SeoSettingsService::splitLines((string) $s['crawl.robots_extra']) as $extra) {
                $lines[] = $extra;
            }

            // AI tarayıcıları (GEO): erişim kapalıysa tümüne Disallow: /; açıkken yalnız kapalı yollar.
            $bots = array_values(array_filter(array_map('strval', (array) $s['crawl.ai_bots'])));
            $closed = array_values(array_filter(array_map('strval', (array) $s['crawl.ai_disallow_paths'])));

            if ($bots !== [] && (! $s['crawl.ai_crawlers_allowed'] || $closed !== [])) {
                foreach ($bots as $bot) {
                    $lines[] = '';
                    $lines[] = 'User-agent: '.$bot;

                    foreach ($s['crawl.ai_crawlers_allowed'] ? $closed : ['/'] as $rule) {
                        $lines[] = 'Disallow: '.$rule;
                    }
                }
            }

            if ($s['crawl.sitemap_enabled']) {
                $lines[] = '';
                $lines[] = 'Sitemap: '.$this->canonicalBase($website).'/sitemap.xml';
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Sitemap girdileri (faz 21). robots_index kapalıysa BOŞ döner; türler ve hariç yollar ayardan.
     *
     * @return array<int, array{loc: string, lastmod: string|null, changefreq: string, priority: string}>
     */
    public function sitemapEntries(Website $website): array
    {
        if (! $website->robots_index) {
            return [];
        }

        $s = $this->settings->for($website);
        $types = (array) $s['crawl.sitemap_types'];
        $has = fn (string $type) => in_array($type, $types, true);
        $base = $this->canonicalBase($website);
        $entries = [[
            'loc' => $base.'/',
            'lastmod' => null,
            'changefreq' => 'daily',
            'priority' => '1.0',
        ]];

        if ($has('pages')) {
            foreach ($this->contents->livePages($website) as $page) {
                if (! $page->noindex) {
                    $entries[] = ['loc' => $base.$page->path(), 'lastmod' => $page->updated_at?->toAtomString(), 'changefreq' => 'monthly', 'priority' => '0.7'];
                }
            }
        }

        // Lokasyonlar yalnızca varsayılan (Ofisvio) sitede yayınlanır.
        if ($website->is_default) {
            if ($has('locations')) {
                $locations = $this->geo->publishedLocations();
                if ($locations->isNotEmpty()) {
                    $entries[] = ['loc' => $base.'/lokasyonlar', 'lastmod' => null, 'changefreq' => 'weekly', 'priority' => '0.8'];
                }
                foreach ($locations as $location) {
                    $entries[] = ['loc' => $base.$location->path(), 'lastmod' => $location->updated_at?->toAtomString(), 'changefreq' => 'monthly', 'priority' => '0.7'];
                }
            }

            // Hizmet sayfaları (faz 4).
            if ($has('services')) {
                $services = $this->services->active($website);
                if ($services->isNotEmpty()) {
                    $entries[] = ['loc' => $base.'/cozumler', 'lastmod' => null, 'changefreq' => 'monthly', 'priority' => '0.8'];
                }
                foreach ($services as $service) {
                    $entries[] = ['loc' => $base.$service->path(), 'lastmod' => $service->updated_at?->toAtomString(), 'changefreq' => 'monthly', 'priority' => '0.7'];
                }
            }

            // Etkinlikler (faz 39d): yalnız yayındaki yaklaşanlar; franchise sayfası sabit.
            if ($has('events')) {
                $events = $this->events->upcoming($website);
                if ($events->isNotEmpty()) {
                    $entries[] = ['loc' => $base.'/etkinlikler', 'lastmod' => null, 'changefreq' => 'weekly', 'priority' => '0.6'];
                }
                foreach ($events as $event) {
                    $entries[] = ['loc' => $base.$event->path(), 'lastmod' => $event->updated_at?->toAtomString(), 'changefreq' => 'weekly', 'priority' => '0.6'];
                }
            }

            if ($has('pages')) {
                $entries[] = ['loc' => $base.'/franchise', 'lastmod' => null, 'changefreq' => 'monthly', 'priority' => '0.5'];
            }
        }

        $posts = $this->contents->livePosts($website, 1000);

        if ($has('posts') && $posts->isNotEmpty()) {
            $entries[] = ['loc' => $base.'/blog', 'lastmod' => $posts->first()->published_at?->toAtomString(), 'changefreq' => 'weekly', 'priority' => '0.8'];
        }

        // Kategori/etiket sayfaları (faz 18): yalnız yayındaki yazılardan türeyenler; liste noindex ise girmez.
        if (! $s['crawl.noindex_listings']) {
            if ($has('categories')) {
                foreach (array_keys($this->contents->categories($website)) as $categorySlug) {
                    $entries[] = ['loc' => $base.'/blog/kategori/'.$categorySlug, 'lastmod' => null, 'changefreq' => 'weekly', 'priority' => '0.5'];
                }
            }

            if ($has('tags')) {
                foreach (array_keys($this->contents->tags($website)) as $tagSlug) {
                    $entries[] = ['loc' => $base.'/blog/etiket/'.$tagSlug, 'lastmod' => null, 'changefreq' => 'weekly', 'priority' => '0.4'];
                }
            }
        }

        if ($has('posts')) {
            foreach ($posts as $post) {
                if (! $post->noindex) {
                    $entries[] = ['loc' => $base.$post->path(), 'lastmod' => $post->updated_at?->toAtomString(), 'changefreq' => 'monthly', 'priority' => '0.6'];
                }
            }
        }

        $excluded = array_values(array_filter(array_map('strval', (array) $s['crawl.sitemap_exclude'])));
        // Yönlendirilen eski adresler sitemap'e girmez (faz 54): canlı listeden gelseler bile (ör. yönlendirilmiş kategori).
        $redirected = $this->urls->map($website);

        if ($excluded === [] && $redirected === []) {
            return $entries;
        }

        return array_values(array_filter($entries, function (array $entry) use ($excluded, $redirected, $base) {
            $path = substr($entry['loc'], strlen($base)) ?: '/';

            return ! isset($redirected[$path]) && ! self::pathMatches($path, $excluded);
        }));
    }

    /**
     * Yol kalıbı eşleşmesi: tam yol ya da "/on-ek/*" (sonek yıldızı ön ek eşler).
     *
     * @param  array<int, string>  $patterns
     */
    public static function pathMatches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);

            if ($pattern === '') {
                continue;
            }

            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($path, rtrim(substr($pattern, 0, -1), '/').'/') || $path === rtrim(substr($pattern, 0, -1), '/')) {
                    return true;
                }
            } elseif (str_ends_with($pattern, '/')) {
                if (str_starts_with($path, $pattern) || $path === rtrim($pattern, '/')) {
                    return true;
                }
            } elseif ($path === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * llms.txt (faz 44 — GEO): markdown biçiminde site özeti. Otomatik modda GEO tanımları +
     * yayındaki hizmet/lokasyon/sayfa/yazılar; özel modda panel metni olduğu gibi.
     * AI erişimi ya da üretim kapalıysa null (404).
     */
    public function llmsTxt(Website $website): ?string
    {
        $s = $this->settings->for($website);

        if (! $s['geo.llms_enabled'] || ! $s['crawl.ai_crawlers_allowed']) {
            return null;
        }

        if (! $s['geo.llms_auto']) {
            $custom = trim((string) $s['geo.llms_custom']);

            return $custom === '' ? null : $custom."\n";
        }

        $base = $this->canonicalBase($website);
        $closed = array_values(array_filter(array_map('strval', (array) $s['crawl.ai_disallow_paths'])));
        $open = fn (string $path) => ! self::pathMatches($path, $closed);
        $lines = ['# '.$website->name];
        $summary = trim((string) ($s['geo.summary_short'] !== '' ? $s['geo.summary_short'] : ($s['geo.brand_definition'] !== '' ? $s['geo.brand_definition'] : ($website->seo_default_description ?? ''))));

        if ($summary !== '') {
            $lines[] = '';
            $lines[] = '> '.str_replace(["\r\n", "\n"], ' ', $summary);
        }

        $about = [];

        if (trim((string) $s['geo.summary_long']) !== '') {
            $about[] = trim((string) $s['geo.summary_long']);
        }

        foreach (['geo.audience' => 'Hedef kitle', 'geo.expertise' => 'Uzmanlık alanları', 'geo.locations_served' => 'Hizmet verilen lokasyonlar', 'local.service_cities' => 'Servis verilen şehirler', 'local.service_districts' => 'Servis verilen ilçeler'] as $key => $label) {
            $value = is_array($s[$key]) ? implode(', ', array_map('strval', $s[$key])) : trim((string) $s[$key]);

            if ($value !== '') {
                $about[] = '- '.$label.': '.$value;
            }
        }

        $brand = $website->brand();

        foreach (['phone' => 'Telefon', 'email' => 'E-posta', 'address' => 'Adres'] as $key => $label) {
            if ($brand[$key] !== '') {
                $about[] = '- '.$label.': '.$brand[$key];
            }
        }

        if ($about !== []) {
            $lines[] = '';
            $lines[] = '## Hakkında';
            $lines[] = '';
            $lines = array_merge($lines, $about);
        }

        $serviceLines = array_map(fn (string $v) => '- '.$v, (array) $s['geo.services']);

        foreach ($this->services->active($website) as $service) {
            if ($open($service->path())) {
                $serviceLines[] = '- ['.$service->name.']('.$base.$service->path().')'.($service->summary ? ': '.$service->summary : '');
            }
        }

        if ($serviceLines !== []) {
            $lines[] = '';
            $lines[] = '## Hizmetler';
            $lines[] = '';
            $lines = array_merge($lines, $serviceLines);
        }

        if ($website->is_default) {
            $locationLines = [];

            foreach ($this->geo->publishedLocations() as $location) {
                if ($open($location->path())) {
                    $locationLines[] = '- ['.$location->name.']('.$base.$location->path().'): '.implode(', ', array_filter([$location->address_line, $location->district, $location->city]));
                }
            }

            if ($locationLines !== []) {
                $lines[] = '';
                $lines[] = '## Lokasyonlar';
                $lines[] = '';
                $lines = array_merge($lines, $locationLines);
            }
        }

        $important = [];

        foreach ((array) $s['geo.priority_urls'] as $url) {
            $url = trim((string) $url);
            $important[] = '- '.(str_starts_with($url, '/') ? $base.$url : $url);
        }

        foreach ((array) $s['geo.resources'] as $row) {
            if (is_array($row) && ($row['title'] ?? '') !== '' && ($row['url'] ?? '') !== '') {
                $important[] = '- ['.$row['title'].']('.(str_starts_with((string) $row['url'], '/') ? $base.$row['url'] : $row['url']).')';
            }
        }

        foreach ($this->contents->livePages($website) as $page) {
            if (! $page->noindex && $open($page->path())) {
                $important[] = '- ['.$page->title.']('.$base.$page->path().')'.($page->excerpt ? ': '.Str::limit((string) $page->excerpt, 140, '') : '');
            }
        }

        if ($important !== []) {
            $lines[] = '';
            $lines[] = '## Önemli sayfalar';
            $lines[] = '';
            $lines = array_merge($lines, array_values(array_unique($important)));
        }

        $postLines = [];

        foreach ($this->contents->livePosts($website, 20) as $post) {
            if (! $post->noindex && $open($post->path())) {
                $postLines[] = '- ['.$post->title.']('.$base.$post->path().')'.($post->excerpt ? ': '.Str::limit((string) $post->excerpt, 140, '') : '');
            }
        }

        if ($postLines !== []) {
            $lines[] = '';
            $lines[] = '## Yazılar';
            $lines[] = '';
            $lines = array_merge($lines, $postLines);
        }

        $faq = array_values(array_filter($this->settings->rows($website, 'geo.faq'), fn (array $r) => ($r['q'] ?? '') !== '' && ($r['a'] ?? '') !== ''));

        if ($faq !== []) {
            $lines[] = '';
            $lines[] = '## Sık sorulan sorular';

            foreach ($faq as $pair) {
                $lines[] = '';
                $lines[] = '**'.$pair['q'].'**';
                $lines[] = $pair['a'];
            }
        }

        if ($closed !== []) {
            $lines[] = '';
            $lines[] = '## Erişim';
            $lines[] = '';
            $lines[] = '- Kapalı yollar (robots.txt ile de bildirilir): '.implode(', ', $closed);
        }

        $lines[] = '';
        $lines[] = '- Site haritası: '.$base.'/sitemap.xml';

        return implode("\n", $lines)."\n";
    }

    /**
     * HTML site haritası bölümleri (faz 44): yayındaki sayfa/yazı/lokasyon/hizmet/etkinlik bağlantıları.
     *
     * @return array<int, array{label: string, items: array<int, array{title: string, url: string}>}>
     */
    public function htmlSitemap(Website $website): array
    {
        $base = $this->canonicalBase($website);
        $sections = [];
        $pages = $this->contents->livePages($website)->filter(fn (Content $c) => ! $c->noindex)->map(fn (Content $c) => ['title' => $c->title, 'url' => $base.$c->path()])->values()->all();

        if ($pages !== []) {
            $sections[] = ['label' => 'Sayfalar', 'items' => $pages];
        }

        if ($website->is_default) {
            $services = $this->services->active($website)->map(fn ($svc) => ['title' => $svc->name, 'url' => $base.$svc->path()])->values()->all();

            if ($services !== []) {
                $sections[] = ['label' => 'Hizmetler', 'items' => $services];
            }

            $locations = $this->geo->publishedLocations()->map(fn (Location $l) => ['title' => $l->name.' · '.$l->city, 'url' => $base.$l->path()])->values()->all();

            if ($locations !== []) {
                $sections[] = ['label' => 'Lokasyonlar', 'items' => $locations];
            }

            $events = $this->events->upcoming($website)->map(fn ($e) => ['title' => $e->title, 'url' => $base.$e->path()])->values()->all();

            if ($events !== []) {
                $sections[] = ['label' => 'Etkinlikler', 'items' => $events];
            }
        }

        $posts = $this->contents->livePosts($website, 1000)->filter(fn (Content $c) => ! $c->noindex)->map(fn (Content $c) => ['title' => $c->title, 'url' => $base.$c->path()])->values()->all();

        if ($posts !== []) {
            $sections[] = ['label' => 'Yazılar', 'items' => $posts];
        }

        return $sections;
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

    /**
     * Teknik SEO denetimi (faz 44): çift/eksik başlık-açıklama, kırık iç bağlantı, yetim sayfa,
     * yönlendirme zinciri, alt metinsiz görsel, karışık içerik, canonical tutarlılığı. Dış istek yok.
     *
     * @return array<string, array{label: string, items: array<int, string>}>
     */
    public function technicalReport(Website $website): array
    {
        $s = $this->settings->for($website);
        $base = $website->baseUrl();
        $canonicalBase = $this->canonicalBase($website);
        $live = $this->contents->livePages($website)->merge($this->contents->livePosts($website, 1000));
        $report = [
            'duplicate_title' => ['label' => 'Çift başlık', 'items' => []],
            'duplicate_description' => ['label' => 'Çift meta açıklama', 'items' => []],
            'missing_description' => ['label' => 'Eksik meta açıklama', 'items' => []],
            'broken_links' => ['label' => 'Kırık iç bağlantı (404)', 'items' => []],
            'orphan_pages' => ['label' => 'Yetim sayfa (iç bağlantı almıyor)', 'items' => []],
            'redirect_chains' => ['label' => 'Yönlendirme zinciri / döngü', 'items' => []],
            'images_without_alt' => ['label' => 'Alt metinsiz görsel', 'items' => []],
            'mixed_content' => ['label' => 'Karışık içerik (http://)', 'items' => []],
            'canonical' => ['label' => 'Canonical tutarlılığı', 'items' => []],
        ];

        // Bilinen hedefler: sabit yollar + yayındaki içerik + lokasyon/hizmet/etkinlik + kategori/etiket + yönlendirme kaynakları.
        $known = array_fill_keys(self::STATIC_PATHS, true);

        foreach ($live as $content) {
            $known[$content->path()] = true;
        }

        if ($website->is_default) {
            foreach ($this->geo->publishedLocations() as $location) {
                $known[$location->path()] = true;
            }
            foreach ($this->services->active($website) as $service) {
                $known[$service->path()] = true;
            }
            foreach ($this->events->upcoming($website) as $event) {
                $known[$event->path()] = true;
            }
        }

        foreach (array_keys($this->contents->categories($website)) as $slug) {
            $known['/blog/kategori/'.$slug] = true;
        }
        foreach (array_keys($this->contents->tags($website)) as $slug) {
            $known['/blog/etiket/'.$slug] = true;
        }

        $redirects = array_values(array_filter((array) $s['url.redirects'], 'is_array'));

        foreach ($redirects as $redirect) {
            $known[rtrim((string) ($redirect['from'] ?? ''), '*')] = true;
        }

        $inbound = [];

        foreach ($this->contents->navigation($website) as $navPage) {
            $inbound[$navPage->path()] = true;
        }

        foreach ((array) $s['links.keywords'] as $row) {
            if (is_array($row)) {
                $inbound[self::relativePath((string) ($row['url'] ?? ''), $base, $canonicalBase)] = true;
            }
        }

        $byTitle = [];
        $byDescription = [];

        foreach ($live as $content) {
            $label = $content->title.' ('.$content->path().')';
            $title = mb_strtolower(trim((string) ($content->meta_title ?: $content->title)));
            $description = trim((string) ($content->meta_description ?: $content->excerpt));
            $byTitle[$title][] = $label;

            if ($description === '') {
                $report['missing_description']['items'][] = $label;
            } else {
                $byDescription[mb_strtolower($description)][] = $label;
            }

            $body = (string) $content->body;

            if (preg_match_all('/!\[\s*\]\(/', $body) > 0) {
                $report['images_without_alt']['items'][] = $label.' — '.preg_match_all('/!\[\s*\]\(/', $body).' görsel';
            }

            if (preg_match('#\]\(http://#', $body) === 1 || preg_match('#src="http://#', $body) === 1) {
                $report['mixed_content']['items'][] = $label;
            }

            if ($content->parent_slug && $content->kind === ContentKind::PAGE) {
                $inbound[$content->path()] = true; // ebeveyn sayfası alt sayfaları listeler
            }

            preg_match_all('/\]\(([^)\s]+)\)/', $body, $matches);

            foreach ($matches[1] as $href) {
                $path = self::relativePath($href, $base, $canonicalBase);

                if ($path === null) {
                    continue;
                }

                if ($path !== $content->path()) {
                    $inbound[$path] = true;
                }

                if (! isset($known[$path]) && ! $this->prefixKnown($path, $known)) {
                    $report['broken_links']['items'][] = $label.' → '.$href;
                }
            }
        }

        foreach ($byTitle as $labels) {
            if (count($labels) > 1) {
                $report['duplicate_title']['items'][] = implode(' · ', $labels);
            }
        }

        foreach ($byDescription as $labels) {
            if (count($labels) > 1) {
                $report['duplicate_description']['items'][] = implode(' · ', $labels);
            }
        }

        foreach ($live as $content) {
            if (! isset($inbound[$content->path()])) {
                $report['orphan_pages']['items'][] = $content->title.' ('.$content->path().')';
            }
        }

        $sources = [];

        foreach ($redirects as $redirect) {
            $sources[(string) ($redirect['from'] ?? '')] = (string) ($redirect['to'] ?? '');
        }

        foreach ($sources as $from => $to) {
            $target = self::relativePath($to, $base, $canonicalBase);

            if ($target !== null && $target === $from) {
                $report['redirect_chains']['items'][] = $from.' → kendisine (döngü)';
            } elseif ($target !== null && isset($sources[$target])) {
                $report['redirect_chains']['items'][] = $from.' → '.$target.' → '.$sources[$target].' (zincir; doğrudan son hedefe yönlendirin)';
            }
        }

        $host = $this->settings->string($website, 'url.canonical_host');

        if ($host !== '' && $website->domain !== null && $host !== $website->domain && $host !== 'www.'.$website->domain && 'www.'.$host !== $website->domain) {
            $report['canonical']['items'][] = 'Canonical alan adı ('.$host.') site alan adından ('.$website->domain.') farklı — arama motoru başka bir siteyi asıl sayar.';
        }

        if ($host !== '' && $s['url.www'] === 'www' && ! str_starts_with($host, 'www.')) {
            $report['canonical']['items'][] = 'www tercihi "www\'lu" ama canonical alan adı www\'suz.';
        }

        if ($host !== '' && $s['url.www'] === 'non_www' && str_starts_with($host, 'www.')) {
            $report['canonical']['items'][] = 'www tercihi "www\'suz" ama canonical alan adı www ile başlıyor.';
        }

        if (! $s['url.canonical_auto']) {
            $report['canonical']['items'][] = 'Otomatik canonical kapalı — çift içerik riskine karşı açık olmalı.';
        }

        return $report;
    }

    /**
     * Tek içerik için bağlantı denetimi (faz 48 editör): gövdedeki kırık iç bağlantılar, giden iç bağlantı sayısı,
     * bu sayfaya gelen bağlantı sayısı (0 = yetim; yeni içerikte hesaplanmaz).
     *
     * @return array{broken: array<int, string>, outbound: int, inbound: int|null}
     */
    public function linkAudit(Website $website, string $body, ?Content $self = null): array
    {
        $base = $website->baseUrl();
        $canonicalBase = $this->canonicalBase($website);
        $live = $this->contents->livePages($website)->merge($this->contents->livePosts($website, 1000));
        $known = array_fill_keys(self::STATIC_PATHS, true);

        foreach ($live as $content) {
            $known[$content->path()] = true;
        }

        if ($website->is_default) {
            foreach ($this->geo->publishedLocations() as $location) {
                $known[$location->path()] = true;
            }
            foreach ($this->services->active($website) as $service) {
                $known[$service->path()] = true;
            }
        }

        foreach (array_keys($this->contents->categories($website)) as $slug) {
            $known['/blog/kategori/'.$slug] = true;
        }
        foreach (array_keys($this->contents->tags($website)) as $slug) {
            $known['/blog/etiket/'.$slug] = true;
        }

        $broken = [];
        $outbound = 0;
        preg_match_all('/\]\(([^)\s]+)\)/', $body, $matches);

        foreach ($matches[1] as $href) {
            $path = self::relativePath($href, $base, $canonicalBase);

            if ($path === null) {
                continue;
            }

            $outbound++;

            if (! isset($known[$path]) && ! $this->prefixKnown($path, $known)) {
                $broken[] = $href;
            }
        }

        $inbound = null;

        if ($self !== null) {
            $inbound = 0;
            $target = $self->path();

            foreach ($live as $content) {
                if ($content->id !== $self->id && preg_match('/\]\((?:'.preg_quote($base, '/').')?'.preg_quote($target, '/').'[)#?]/', (string) $content->body) === 1) {
                    $inbound++;
                }
            }

            foreach ($this->contents->navigation($website) as $nav) {
                if ($nav->id === $self->id) {
                    $inbound++;
                }
            }
        }

        return ['broken' => array_values(array_unique($broken)), 'outbound' => $outbound, 'inbound' => $inbound];
    }

    /**
     * Bağlantıyı site içi yola indirger; dış/özel şema bağlantı null. Çapa ve sorgu düşer.
     */
    public static function relativePath(string $href, string ...$bases): ?string
    {
        $href = trim($href);

        foreach ($bases as $base) {
            if ($base !== '' && str_starts_with($href, $base)) {
                $href = substr($href, strlen($base)) ?: '/';
                break;
            }
        }

        if (! str_starts_with($href, '/') || str_starts_with($href, '//')) {
            return null;
        }

        $path = (string) (parse_url($href, PHP_URL_PATH) ?: '/');

        return $path !== '/' ? rtrim($path, '/') : '/';
    }

    /** @param  array<string, bool>  $known */
    private function prefixKnown(string $path, array $known): bool
    {
        // Sorgu/alt parça taşıyan dinamik sayfalar (rezervasyon durumu, önizleme) bilinen kök altındaysa geçer.
        foreach (['/rezervasyon/', '/onizleme/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        // Yönlendirme ön ek kalıbı (/eski/*).
        foreach (array_keys($known) as $candidate) {
            if ($candidate !== '/' && str_ends_with($candidate, '/') && str_starts_with($path, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
