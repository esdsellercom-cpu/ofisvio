<?php

namespace App\View\Composers;

use App\Models\Content;
use App\Models\Location;
use App\Services\ContentService;
use App\Services\CurrentWebsite;
use App\Services\InternalLinkService;
use App\Services\SeoService;
use App\Services\SeoSettingsService;
use App\Services\SiteBlockService;
use App\Services\SiteBuilderService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Vitrin görünümleri hangi iskeleti kullanacak?
 *   Ofisvio vitrini -> layouts.site   (pazarlama başlığı, teklif formu, footer)
 *   Müşteri sitesi  -> layouts.tenant (sitenin adı + yayındaki sayfa menüsü)
 * site.content ve site.posts @extends($siteLayout) ile ikisinde de çalışır.
 */
class SiteLayoutComposer
{
    public function __construct(
        private readonly CurrentWebsite $website,
        private readonly ContentService $contents,
        private readonly SeoService $seo,
        private readonly SiteBlockService $blocks,
        private readonly SiteBuilderService $builder,
        private readonly SeoSettingsService $seoSettings,
        private readonly InternalLinkService $links,
        private readonly Request $request,
    ) {}

    public function compose(View $view): void
    {
        $site = $this->website->get();
        $tenant = $this->website->isTenantSite();

        $data = $view->getData();
        $content = $data['content'] ?? null;
        $content = $content instanceof Content ? $content : null;

        // SEO head verisi: içerik sayfası -> içerikten; liste/ana sayfa -> sayfa sabitleri.
        // Composer önce çocuk görünüm, sonra iskelet için çalışır; iskelete çocuktan gelen
        // $seo (liste/kategori sayfası başlığı) korunur, yoksa hesaplanır.
        $seo = null;
        if (str_starts_with($view->name(), 'layouts.') && is_array($data['seo'] ?? null)) {
            $seo = $data['seo'];
        } elseif ($site !== null) {
            $location = $data['location'] ?? null;
            $location = $location instanceof Location ? $location : null;

            $seo = match (true) {
                $content !== null => $this->seo->head($site, $content),
                // Rezervasyon: uygunluk sayfası indekslenir; durum sayfası kişisel veri taşır → noindex.
                str_ends_with($view->name(), 'site.booking') => $this->seo->head($site, null, '/rezervasyon', 'Toplantı odası rezervasyonu', 'Lokasyon ve gün seçin; odaların uygunluğu canlı hesaplanır.'),
                str_ends_with($view->name(), 'site.booking-status') => ['robots' => 'noindex, nofollow'] + $this->seo->head($site, null, '/rezervasyon', 'Rezervasyon durumu'),
                $location !== null => $this->seo->locationHead($site, $location),
                str_ends_with($view->name(), 'site.locations') => $this->seo->head($site, null, '/lokasyonlar', 'Lokasyonlar', 'Ofisvio şubeleri: şehir, bölge ve sunulan çözümlere göre.'),
                str_ends_with($view->name(), 'site.posts') => $this->seo->head($site, null, '/blog', 'Yazılar'),
                str_ends_with($view->name(), 'site.sitemap') => $this->seo->head($site, null, '/site-haritasi', 'Site haritası', 'Yayındaki tüm sayfa, yazı, lokasyon ve hizmet bağlantıları.'),
                // Etiket/kategori sayfaları 'listing': crawl.noindex_listings ayarı bunlara uygulanır (faz 44).
                str_ends_with($view->name(), 'site.tag') => $this->seo->head($site, null, '/blog/etiket/'.($data['tagSlug'] ?? ''), '#'.($data['tagName'] ?? 'Etiket').' yazıları', null, 'listing'),
                str_ends_with($view->name(), 'site.category') => $this->seo->head($site, null, '/blog/kategori/'.($data['categorySlug'] ?? ''), ($data['categoryName'] ?? 'Kategori').' yazıları', null, 'listing'),
                $tenant => $this->seo->head($site),
                default => $this->seo->head($site, null, '/', 'Şirketinizin adresi bugün hazır olsun', 'Sanal ofis, hazır ofis ve coworking. Tescil adresi, çağrı ve kargo karşılama, saatlik toplantı odası.'),
            };
        }

        $brand = $site?->brand() ?? ['name' => (string) config('ofisvio.brand.name'), 'legal_name' => (string) config('ofisvio.brand.name'), 'phone' => '', 'phone_href' => '', 'email' => '', 'tagline' => '', 'address' => '', 'whatsapp' => '', 'whatsapp_href' => '', 'hours' => [], 'announcement' => null];
        $texts = $this->blocks->texts($site);

        // Görsel editör / önizleme (faz 49): header/footer metin taslağı canlının üstüne biner; yayınlanana kadar vitrine çıkmaz.
        if ($site !== null && ($data['preview'] ?? false) && ! $tenant) {
            $texts = array_merge($texts, $this->builder->globalsDraft($site)['texts']);
        }

        if ($brand['whatsapp_href'] !== '' && ($texts['whatsapp_message'] ?? '') !== '') {
            $brand['whatsapp_href'] .= '?text='.rawurlencode($texts['whatsapp_message']);
        }

        // KVKK bağlantısı: yayındaki 'aydinlatma' ya da 'kvkk' slug'lı sayfa; yoksa ana sayfa (ölü # bağlantısı yok).
        $kvkk = $this->contents->livePages($site)->first(fn (Content $p) => str_contains($p->slug, 'aydinlatma') || str_contains($p->slug, 'kvkk'));

        // Üst menü: yayınlanmış bölümlerin çapalarından (sayfa kurucu) — gizli bölüme ölü bağlantı yok.
        $navLinks = [];

        if ($site !== null && ! $tenant) {
            $labels = ['solutions' => 'nav_solutions', 'journey' => 'nav_journey', 'locations' => 'nav_locations', 'meeting' => 'nav_meeting', 'pricing' => 'nav_pricing'];

            // Önizleme/editörde menü taslağın çapalarından (yayınlanınca aynı olacak görünüm).
            foreach (($data['preview'] ?? false) ? $this->builder->draftForPreview($site) : $this->builder->published($site) as $section) {
                if (isset($labels[$section['type']]) && $section['anchor'] !== null && ($texts[$labels[$section['type']]] ?? '') !== '') {
                    $navLinks[] = ['label' => $texts[$labels[$section['type']]], 'href' => '#'.$section['anchor'], 'key' => $labels[$section['type']]];
                }
            }
        }

        if ((str_ends_with($view->name(), 'layouts.site') || str_ends_with($view->name(), 'layouts.tenant')) && ($data['preview'] ?? false) && is_array($seo)) {
            $seo['robots'] = 'noindex, nofollow';
        }

        // Parametreli istek (?utm=…, ?sayfa=2): ayar kapalıysa noindex; canonical zaten parametresiz (faz 44).
        if (is_array($seo) && $site !== null && $this->request->getQueryString() !== null && $this->request->getQueryString() !== '' && ! $this->seoSettings->bool($site, 'crawl.index_query_urls')) {
            $seo['robots'] = str_starts_with((string) ($seo['robots'] ?? ''), 'noindex') ? $seo['robots'] : 'noindex, follow';
        }

        // İçerik sayfası (faz 44): otomatik iç bağlantı + tembel görsel, görünür breadcrumb, ilgili yazılar anahtarı.
        $contentExtras = [];

        if ($content !== null && $site !== null && str_ends_with($view->name(), 'site.content')) {
            $contentExtras = [
                'bodyHtml' => $this->links->apply($site, $content, $content->renderedBody()),
                'breadcrumbs' => $this->seoSettings->bool($site, 'links.breadcrumb_enabled') ? $this->seo->breadcrumbItems($site, $content) : [],
                'showRelated' => $this->seoSettings->bool($site, 'links.related_enabled'),
            ];
        }

        $view->with($contentExtras + [
            'siteNavLinks' => $navLinks,
            'kvkkUrl' => $kvkk?->path() ?? '/',
            'brand' => $brand,
            'texts' => $texts,
            'leadOptions' => $this->blocks->solutionOptions($site),
            'siteLayout' => $tenant ? 'layouts.tenant' : 'layouts.site',
            'currentWebsite' => $site,
            'tenantNav' => $tenant ? $this->contents->navigation($site) : collect(),
            'tenantHasPosts' => $tenant && $this->contents->livePosts($site, 1)->isNotEmpty(),
            'seo' => $seo,
        ]);
    }
}
