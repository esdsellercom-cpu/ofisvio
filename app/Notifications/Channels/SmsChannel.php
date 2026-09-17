<?php

namespace App\Notifications\Channels;

use App\Integrations\Sms\SmsProviderInterface;
use App\Models\NotificationLog;

class SmsChannel implements ChannelInterface
{
    public function __construct(private readonly SmsProviderInterface $provider) {}

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
        return 'SMS sağlayıcısı kapalı ya da secret eksik (SMS_ENABLED / SMS_BASE_URL / SMS_API_KEY).';
    }

    public function send(NotificationLog $log): string
    {
        return $this->provider->send($log->recipient, mb_substr($log->body, 0, 600));
    }
}
