<?php

namespace App\Listeners;

use App\Events\SiteChromePublished;
use App\Services\LegalDocumentService;

/** Audit F-07: footer yayınında seçili yasal sayfaların özeti değiştiyse yeni sürüm. Otomatik keşifle kaydolur. */
class SyncLegalVersionsOnChromePublish
{
    public function __construct(private readonly LegalDocumentService $legal) {}

    public function handle(SiteChromePublished $event): void
    {
        if ($event->area === 'footer') {
            $this->legal->syncFromFooter($event->actor, $event->website->fresh(), 'Footer yayını');
        }
    }
}
