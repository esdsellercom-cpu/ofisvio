<?php

namespace App\View\Composers;

use App\Services\ContentService;
use App\Services\CurrentWebsite;
use Illuminate\View\View;

/**
 * Footer'daki yasal bağlantılar CMS'ten (yayındaki sayfalar). Varsayılan
 * website henüz seed edilmemişse boş liste döner — vitrin CMS yüzünden çökmez.
 */
class SiteFooterComposer
{
    public function __construct(
        private readonly ContentService $contents,
        private readonly CurrentWebsite $website,
    ) {}

    public function compose(View $view): void
    {
        $view->with('legalPages', $this->contents->livePages($this->website->get()));
    }
}
