<?php

namespace App\View\Composers;

use App\Services\ContentService;
use App\Services\CurrentWebsite;
use App\Services\SiteBlockService;
use Illuminate\View\View;

/**
 * Footer'daki yasal bağlantılar CMS'ten (yayındaki sayfalar). Varsayılan
 * website henüz seed edilmemişse boş liste döner — vitrin CMS yüzünden çökmez.
 */
class SiteFooterComposer
{
    public function __construct(
        private readonly ContentService $contents,
        private readonly SiteBlockService $blocks,
        private readonly CurrentWebsite $website,
    ) {}

    public function compose(View $view): void
    {
        $site = $this->website->get();

        $view->with([
            'legalPages' => $this->contents->livePages($site),
            'blocks' => $this->blocks->all($site),
            'brand' => $site?->brand() ?? ['name' => (string) config('ofisvio.brand.name'), 'legal_name' => (string) config('ofisvio.brand.name'), 'phone' => '', 'phone_href' => '', 'email' => '', 'tagline' => '', 'address' => '', 'whatsapp' => '', 'whatsapp_href' => '', 'hours' => [], 'announcement' => null],
        ]);
    }
}
