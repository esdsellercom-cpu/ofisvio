<?php

namespace App\View\Composers;

use App\Services\ContentService;
use App\Services\CurrentWebsite;
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
    ) {}

    public function compose(View $view): void
    {
        $site = $this->website->get();
        $tenant = $this->website->isTenantSite();

        $view->with([
            'siteLayout' => $tenant ? 'layouts.tenant' : 'layouts.site',
            'currentWebsite' => $site,
            'tenantNav' => $tenant ? $this->contents->livePages($site) : collect(),
        ]);
    }
}
