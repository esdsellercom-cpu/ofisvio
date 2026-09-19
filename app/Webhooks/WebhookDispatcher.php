<?php

namespace App\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Olay yayıcı (faz 61c): `emit(olay, payload)` abone olan aktif uçlar için teslimat satırı açar ve işlemi
 * kuyruğa atar (afterCommit: kaynak kaydı geri alınırsa webhook gitmez). Servisleri asla kırmaz: tablo yoksa ya da
 * kuyruk hata verirse sessiz geçer (log). Payload'a `event`, `occurred_at`, `delivery_id` zarfı eklenir.
 */
class WebhookDispatcher
{
    /** @param  array<string, mixed>  $payload */
    public function emit(string $event, array $payload): void
    {
        if (! WebhookEvents::valid($event)) {
            return;
        }

        try {
            if (! Schema::hasTable('webhook_endpoints')) {
                return;
            }

            $endpoints = WebhookEndpoint::query()->where('is_active', true)->get()->filter(fn (WebhookEndpoint $e) => $e->subscribed($event));

            foreach ($endpoints as $endpoint) {
                $this->queue($endpoint, $event, $payload, false);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Tek uca teslimat oluştur + kuyruğa at (panel testi/tekrar gönderimi de buradan geçer).
     *
     * @param  array<string, mixed>  $payload
     */
    public function queue(WebhookEndpoint $endpoint, string $event, array $payload, bool $manual): WebhookDelivery
    {
        $deliveryId = 'whd_'.Str::lower(Str::random(24));
        $envelope = ['event' => $event, 'delivery_id' => $deliveryId, 'occurred_at' => now()->toIso8601String(), 'data' => $payload];

        $delivery = WebhookDelivery::create([
            'endpoint_id' => $endpoint->id,
            'event' => $event,
            'delivery_id' => $deliveryId,
            'payload' => WebhookPayload::redact($envelope),
            'body' => json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'manual' => $manual,
        ]);

        DB::afterCommit(fn () => DeliverWebhook::dispatch($delivery->id));

        return $delivery;
    }
}
