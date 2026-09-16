<?php

namespace App\Integrations;

use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/**
 * Webhook güvenliği (faz 5). Gelen her çağrı:
 *   1. sağlayıcı açık ve webhook_secret tanımlı (yoksa 404 — varlık sızdırmaz),
 *   2. HMAC-SHA256(gövde ham bayt + "." + zaman damgası) imzası header'da ve
 *      sabit zamanlı karşılaştırmayla eşleşiyor (X-Ofisvio-Signature),
 *   3. zaman damgası toleransta (X-Ofisvio-Timestamp; replay savunması),
 *   4. olay kimliği (X-Ofisvio-Event-Id) sağlayıcı içinde tekil — tekrar gelen
 *      olay 200 ile kabul edilir ama İKİNCİ KEZ KAYDEDİLMEZ (idempotent).
 * Gövde JSON olarak saklanır, işleme (status) sağlayıcı adaptörünün işidir.
 */
class WebhookReceiver
{
    public const HEADER_SIGNATURE = 'X-Ofisvio-Signature';

    public const HEADER_TIMESTAMP = 'X-Ofisvio-Timestamp';

    public const HEADER_EVENT_ID = 'X-Ofisvio-Event-Id';

    public function __construct(private readonly SecretStore $secrets) {}

    /** @return array{status: int, event: WebhookEvent|null, reason: string} */
    public function receive(string $provider, Request $request): array
    {
        if (! is_array(config("integrations.providers.{$provider}")) || ! $this->secrets->enabled($provider)) {
            return ['status' => 404, 'event' => null, 'reason' => 'provider'];
        }

        $secret = $this->secrets->webhookSecret($provider);

        if ($secret === null) {
            return ['status' => 404, 'event' => null, 'reason' => 'no-secret'];
        }

        $timestamp = (string) $request->header(self::HEADER_TIMESTAMP, '');
        $signature = (string) $request->header(self::HEADER_SIGNATURE, '');
        $eventId = trim((string) $request->header(self::HEADER_EVENT_ID, ''));

        if ($timestamp === '' || $signature === '' || $eventId === '' || strlen($eventId) > 190) {
            return ['status' => 400, 'event' => null, 'reason' => 'headers'];
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > (int) config('integrations.webhook_tolerance_seconds', 300)) {
            return ['status' => 401, 'event' => null, 'reason' => 'timestamp'];
        }

        $expected = hash_hmac('sha256', $request->getContent().'.'.$timestamp, $secret);

        if (! hash_equals($expected, strtolower($signature))) {
            return ['status' => 401, 'event' => null, 'reason' => 'signature'];
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return ['status' => 400, 'event' => null, 'reason' => 'json'];
        }

        try {
            $event = WebhookEvent::create([
                'provider' => $provider,
                'event_id' => $eventId,
                'payload' => $payload,
                'received_at' => now(),
                'source_ip' => (string) $request->ip(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Aynı olay yeniden teslim edildi: kabul et, kaydetme.
            return ['status' => 200, 'event' => null, 'reason' => 'duplicate'];
        }

        return ['status' => 202, 'event' => $event, 'reason' => 'stored'];
    }
}
