<?php

namespace App\Webhooks;

/**
 * Teslimat logu için payload maskeleme (faz 61c): kişisel veri (ad, e-posta, telefon, adres, not) ve secret
 * benzeri anahtarlar saklanan kopyada "***" olur; kimlik/durum/tutar/zaman kalır. Gönderilen gövde maskelenmez.
 */
final class WebhookPayload
{
    private const REDACT = ['name', 'first_name', 'last_name', 'customer_name', 'email', 'customer_email', 'phone', 'customer_phone', 'address', 'address_line', 'note', 'message', 'ip', 'password', 'secret', 'token', 'api_key'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::redact($value);
            } elseif (in_array(strtolower((string) $key), self::REDACT, true) && $value !== null && $value !== '') {
                $payload[$key] = '***';
            }
        }

        return $payload;
    }

    /** İmza: t=<zaman>,v1=<hmac_sha256(zaman . "." . gövde, secret)> — alıcı aynı formülle doğrular. */
    public static function signature(string $body, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
