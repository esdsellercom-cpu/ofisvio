<?php

namespace App\Listeners;

use App\Events\ContentPublicationChanged;
use App\Webhooks\WebhookDispatcher;

/**
 * Giden webhook (faz 61c): içerik yayına alınınca `content.published` (kaldırma olayı gönderilmez). Otomatik keşifle kaydolur.
 */
class EmitWebhookOnContentPublished
{
    public function __construct(private readonly WebhookDispatcher $webhooks) {}

    public function handle(ContentPublicationChanged $event): void
    {
        if (! $event->live) {
            return;
        }

        $c = $event->content;
        $website = $c->website;
        $this->webhooks->emit('content.published', [
            'content_id' => $c->id,
            'kind' => $c->kind,
            'slug' => $c->slug,
            'title' => $c->title,
            'path' => $c->path(),
            'url' => $website !== null ? $website->baseUrl().$c->path() : null,
            'published_at' => $c->published_at?->toIso8601String(),
        ]);
    }
}
