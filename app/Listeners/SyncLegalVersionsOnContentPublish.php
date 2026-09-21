<?php

namespace App\Listeners;

use App\Events\ContentPublicationChanged;
use App\Services\LegalDocumentService;

/** Audit F-07: yasal sayfa olarak seçili içerik yeniden yayınlanınca (gövde değiştiyse) yeni sürüm. */
class SyncLegalVersionsOnContentPublish
{
    public function __construct(private readonly LegalDocumentService $legal) {}

    public function handle(ContentPublicationChanged $event): void
    {
        if ($event->live) {
            $this->legal->syncFromContent(null, $event->content);
        }
    }
}
