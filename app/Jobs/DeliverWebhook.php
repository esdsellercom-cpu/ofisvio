<?php

namespace App\Jobs;

use App\Integrations\Gateway;
use App\Models\WebhookDelivery;
use App\Webhooks\WebhookPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Giden webhook teslimatı (faz 61c). Gövde teslimat satırındaki şifreli kopyadan okunur; imza
 * `X-Ofisvio-Signature: t=<zaman>,v1=<hmac>` (secret yalnız burada, bellekte). Başarısızlıkta (2xx dışı ya da
 * bağlantı hatası) ucun `retry_max` sınırına kadar artan aralıkla (1 dk, 5 dk, 30 dk, 2 sa, 12 sa) yeniden
 * kuyruğa girer; sınır dolunca `failed` — panelden "tekrar gönder" yeni denemeyi açar.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Yeniden deneme aralıkları (saniye); dizin = tamamlanan deneme sayısı - 1. */
    public const BACKOFF = [60, 300, 1800, 7200, 43200];

    public int $tries = 1; // yeniden deneme kendi mantığıyla (uç bazlı retry_max)

    public function __construct(public readonly int $deliveryId) {}

    public function handle(Gateway $gateway): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null || $delivery->status === 'success' || $delivery->endpoint === null) {
            return;
        }

        $endpoint = $delivery->endpoint;
        $body = (string) $delivery->body;
        $timestamp = time();
        $headers = [
            'Content-Type' => 'application/json',
            'X-Ofisvio-Event' => $delivery->event,
            'X-Ofisvio-Delivery' => $delivery->delivery_id,
            'X-Ofisvio-Timestamp' => (string) $timestamp,
            'X-Ofisvio-Signature' => WebhookPayload::signature($body, (string) $endpoint->secret, $timestamp),
        ];

        try {
            $result = $gateway->deliver($endpoint->url, $body, $headers, $endpoint->timeout_seconds);
        } catch (RuntimeException $e) {
            $result = ['status' => null, 'duration_ms' => 0, 'error' => mb_substr($e->getMessage(), 0, 190)];
        }

        $ok = $result['status'] !== null && $result['status'] >= 200 && $result['status'] < 300;
        $attempts = $delivery->attempts + 1;
        $retry = ! $ok && $attempts <= $endpoint->retry_max;
        $delay = self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1];

        $delivery->forceFill([
            'attempts' => $attempts,
            'status' => $ok ? 'success' : ($retry ? 'pending' : 'failed'),
            'response_status' => $result['status'],
            'duration_ms' => $result['duration_ms'],
            'error' => $ok ? null : ($result['error'] ?? ('HTTP '.$result['status'])),
            'next_retry_at' => $retry ? now()->addSeconds($delay) : null,
            'delivered_at' => $ok ? now() : $delivery->delivered_at,
        ])->save();

        $endpoint->forceFill($ok ? ['last_success_at' => now()] : ['last_failure_at' => now()])->save();

        if ($retry) {
            self::dispatch($delivery->id)->delay(now()->addSeconds($delay));
        }
    }
}
