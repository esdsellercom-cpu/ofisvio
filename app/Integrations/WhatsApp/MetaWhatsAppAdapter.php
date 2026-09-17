<?php

namespace App\Integrations\WhatsApp;

use App\Integrations\Gateway;
use App\Integrations\SecretStore;
use App\Services\SettingsService;
use RuntimeException;

/**
 * Meta WhatsApp Cloud API adaptörü. İstek Gateway üzerinden (SSRF/secret/log kuralları).
 *
 * İşletme tarafından başlatılan mesajlar 24 saatlik pencere dışında onaylı ŞABLON
 * ister: `whatsapp.template_name` ayarı doluysa tek gövde parametreli şablon,
 * boşsa düz metin gönderilir (yalnız açık oturumda teslim edilir).
 */
class MetaWhatsAppAdapter implements WhatsAppProviderInterface
{
    public const PROVIDER = 'whatsapp';

    public function __construct(
        private readonly Gateway $gateway,
        private readonly SecretStore $secrets,
        private readonly SettingsService $settings,
    ) {}

    public function name(): string
    {
        return 'meta_cloud';
    }

    public function isConfigured(): bool
    {
        return $this->secrets->enabled(self::PROVIDER) && $this->secrets->missing(self::PROVIDER) === [];
    }

    public function sendText(string $toE164, string $body): string
    {
        $phoneId = $this->secrets->get(self::PROVIDER, 'phone_number_id');
        $template = trim($this->settings->string('whatsapp.template_name'));
        $to = ltrim($toE164, '+');

        $payload = $template !== ''
            ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => [
                'name' => $template,
                'language' => ['code' => $this->settings->string('whatsapp.template_locale')],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $body]]]],
            ]]
            : ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $body]];

        $response = $this->gateway->request(self::PROVIDER, 'POST', "/{$phoneId}/messages", [
            'headers' => ['Authorization' => 'Bearer '.$this->secrets->get(self::PROVIDER, 'access_token')],
            'json' => $payload,
        ]);

        if (! $response->successful()) {
            $code = (string) ($response->json('error.code') ?? $response->status());

            throw new RuntimeException('whatsapp:'.$code.' '.mb_substr((string) ($response->json('error.message') ?? ''), 0, 150));
        }

        return (string) ($response->json('messages.0.id') ?? '');
    }
}
