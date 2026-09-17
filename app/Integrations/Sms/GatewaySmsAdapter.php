<?php

namespace App\Integrations\Sms;

use App\Integrations\Gateway;
use App\Integrations\SecretStore;
use RuntimeException;

/**
 * Genel HTTP SMS adaptörü: POST {base_url}{send_path} {to, text} + Bearer api_key.
 * Sağlayıcıya özel şema gerekirse yeni adaptör yazılır; arayüz değişmez.
 */
class GatewaySmsAdapter implements SmsProviderInterface
{
    public const PROVIDER = 'sms';

    public function __construct(private readonly Gateway $gateway, private readonly SecretStore $secrets) {}

    public function name(): string
    {
        return 'http_sms';
    }

    public function isConfigured(): bool
    {
        return $this->secrets->enabled(self::PROVIDER) && $this->secrets->missing(self::PROVIDER) === [];
    }

    public function send(string $toE164, string $body): string
    {
        $response = $this->gateway->request(self::PROVIDER, 'POST', (string) config('integrations.providers.sms.send_path', '/messages'), [
            'headers' => ['Authorization' => 'Bearer '.$this->secrets->get(self::PROVIDER, 'api_key')],
            'json' => ['to' => $toE164, 'text' => $body],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('sms:'.$response->status());
        }

        return (string) ($response->json('id') ?? $response->json('message_id') ?? '');
    }
}
