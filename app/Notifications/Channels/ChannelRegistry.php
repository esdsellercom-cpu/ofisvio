<?php

namespace App\Notifications\Channels;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/** Kanal adı → adaptör (container'dan; testte HTTP sahtelemeyle gerçek adaptör koşar). */
class ChannelRegistry
{
    /** Kanal adları (raporlama/sağlık ekranı için). */
    public const CHANNELS = ['whatsapp', 'sms', 'email', 'in_app'];

    private const MAP = [
        'whatsapp' => WhatsAppChannel::class,
        'sms' => SmsChannel::class,
        'email' => EmailChannel::class,
        'in_app' => InAppChannel::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function for(string $channel): ChannelInterface
    {
        if (! isset(self::MAP[$channel])) {
            throw new InvalidArgumentException("Bilinmeyen bildirim kanalı: {$channel}");
        }

        return $this->container->make(self::MAP[$channel]);
    }
}
