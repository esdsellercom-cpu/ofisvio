<?php

namespace App\View\Composers;

use App\Models\Content;
use App\Services\ContentService;
use App\Services\CurrentWebsite;
use App\Services\SeoService;
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
    ) {}

    public function compose(View $view): void
    {
        $site = $this->website->get();
        $tenant = $this->website->isTenantSite();

        $data = $view->getData();
        $content = $data['content'] ?? null;
        $content = $content instanceof Content ? $content : null;

        // SEO head verisi: içerik sayfası -> içerikten; liste/ana sayfa -> sayfa sabitleri.
        $seo = null;
        if ($site !== null) {
            $seo = match (true) {
                $content !== null => $this->seo->head($site, $content),
                str_ends_with($view->name(), 'site.posts') => $this->seo->head($site, null, '/blog', 'Yazılar'),
                $tenant => $this->seo->head($site),
                default => $this->seo->head($site, null, '/', 'Şirketinizin adresi bugün hazır olsun', 'Sanal ofis, hazır ofis ve coworking. Tescil adresi, çağrı ve kargo karşılama, saatlik toplantı odası.'),
            };
        }

        $view->with([
            'siteLayout' => $tenant ? 'layouts.tenant' : 'layouts.site',
            'currentWebsite' => $site,
            'tenantNav' => $tenant ? $this->contents->livePages($site) : collect(),
            'seo' => $seo,
        ]);
    }
}
