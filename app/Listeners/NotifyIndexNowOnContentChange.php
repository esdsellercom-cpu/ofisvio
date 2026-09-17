<?php

namespace App\Listeners;

use App\Events\ContentPublicationChanged;
use App\Jobs\NotifyIndexNow;
use App\Services\SeoSettingsService;

/**
 * IndexNow (faz 44): site ayarı açıksa ve olay türü (yayın/kaldırma) bildirime dahilse adres kuyruğa düşer.
 * Otomatik keşifle kaydolur (EventServiceProvider'a elle eklenmez — çift dinleyici).
 */
class NotifyIndexNowOnContentChange
{
    public function __construct(private readonly SeoSettingsService $settings) {}

    public function handle(ContentPublicationChanged $event): void
    {
        $website = $event->content->website;

        if ($website === null || ! $this->settings->bool($website, 'indexing.indexnow_enabled') || $this->settings->string($website, 'indexing.indexnow_key') === '') {
            return;
        }

        if ($event->live ? ! $this->settings->bool($website, 'indexing.notify_on_publish') : ! $this->settings->bool($website, 'indexing.notify_on_delete')) {
            return;
        }

        $urls = [$website->baseUrl().$event->content->path()];

        if ($this->settings->bool($website, 'indexing.notify_sitemap')) {
            $urls[] = $website->baseUrl().'/sitemap.xml';
        }

        NotifyIndexNow::dispatch($website->id, $urls);
    }
}
