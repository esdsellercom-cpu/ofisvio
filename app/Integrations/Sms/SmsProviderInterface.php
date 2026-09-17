<?php

namespace App\Integrations\Sms;

/** SMS sağlayıcı soyutlaması — Gateway üzerinden; sağlayıcıya özel alanlar adaptörde. */
interface SmsProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;

    /** @throws \RuntimeException */
    public function send(string $toE164, string $body): string;
}
