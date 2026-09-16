<?php

namespace App\View\Composers;

use App\Models\Content;
use App\Models\Location;
use App\Services\ContentService;
use App\Services\CurrentWebsite;
use App\Services\SeoService;
use App\Services\SiteBlockService;
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
                $location !== null => $this->seo->locationHead($site, $location),
                str_ends_with($view->name(), 'site.locations') => $this->seo->head($site, null, '/lokasyonlar', 'Lokasyonlar', 'Ofisvio şubeleri: şehir, bölge ve sunulan çözümlere göre.'),
                str_ends_with($view->name(), 'site.posts') => $this->seo->head($site, null, '/blog', 'Yazılar'),
                str_ends_with($view->name(), 'site.tag') => $this->seo->head($site, null, '/blog/etiket/'.($data['tagSlug'] ?? ''), '#'.($data['tagName'] ?? 'Etiket').' yazıları'),
                str_ends_with($view->name(), 'site.category') => $this->seo->head($site, null, '/blog/kategori/'.($data['categorySlug'] ?? ''), ($data['categoryName'] ?? 'Kategori').' yazıları'),
                $tenant => $this->seo->head($site),
                default => $this->seo->head($site, null, '/', 'Şirketinizin adresi bugün hazır olsun', 'Sanal ofis, hazır ofis ve coworking. Tescil adresi, çağrı ve kargo karşılama, saatlik toplantı odası.'),
            };
        }

        $view->with([
            'brand' => $site?->brand() ?? (array) config('ofisvio.brand') + ['address' => ''],
            'texts' => $this->blocks->texts($site),
            'siteLayout' => $tenant ? 'layouts.tenant' : 'layouts.site',
            'currentWebsite' => $site,
            'tenantNav' => $tenant ? $this->contents->navigation($site) : collect(),
            'tenantHasPosts' => $tenant && $this->contents->livePosts($site, 1)->isNotEmpty(),
            'seo' => $seo,
        ]);
    }
}
