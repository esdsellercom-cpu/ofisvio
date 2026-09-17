<?php

namespace App\Notifications\Channels;

use App\Integrations\WhatsApp\WhatsAppProviderInterface;
use App\Models\NotificationLog;

class WhatsAppChannel implements ChannelInterface
{
    public function __construct(private readonly WhatsAppProviderInterface $provider) {}

    public function providerName(): string
    {
        return $this->provider->name();
    }

    public function isAvailable(): bool
    {
        return $this->provider->isConfigured();
    }

    public function unavailableReason(): string
    {
        return 'WhatsApp sağlayıcısı kapalı ya da secret eksik (WHATSAPP_ENABLED / WHATSAPP_ACCESS_TOKEN / WHATSAPP_PHONE_NUMBER_ID).';
    }

    public function send(NotificationLog $log): string
    {
        return $this->provider->sendText($log->recipient, $log->body);
    }
}
