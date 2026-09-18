<?php

namespace App\Providers;

use App\Integrations\Ai\AiProviderInterface;
use App\Integrations\Ai\AnthropicAdapter;
use App\Integrations\Sms\GatewaySmsAdapter;
use App\Integrations\Sms\SmsProviderInterface;
use App\Integrations\WhatsApp\MetaWhatsAppAdapter;
use App\Integrations\WhatsApp\WhatsAppProviderInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Bildirim merkezi bağları (master prompt §14–15). Sağlayıcı adaptörleri gerçek
 * adaptörlerdir; hiçbir ortamda "mock adapter" bağlanmaz — testler HTTP sahtelemesiyle
 * Gateway'i taklit eder, kod yolu üretimle birebir aynıdır.
 */
class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WhatsAppProviderInterface::class, MetaWhatsAppAdapter::class);
        $this->app->bind(SmsProviderInterface::class, GatewaySmsAdapter::class);
        // AI Content Engine (faz 60e): tek sağlayıcı adaptörü, Gateway üzerinden.
        $this->app->bind(AiProviderInterface::class, AnthropicAdapter::class);
    }

    // Dinleyiciler Laravel olay keşfiyle (app/Listeners) bağlanır; burada tekrar bağlamak çift bildirim üretirdi.
}
