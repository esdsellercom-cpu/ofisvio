<?php

namespace App\Integrations\WhatsApp;

/**
 * WhatsApp sağlayıcı soyutlaması (master prompt §15). Domain katmanı yalnız bunu
 * bilir; somut adaptör Integration Gateway üzerinden konuşur. Üretimde sahte
 * adaptör bağlanmaz (NotificationServiceProvider); testler HTTP sahteleme ile gerçek
 * adaptörü koşturur.
 */
interface WhatsAppProviderInterface
{
    public function name(): string;

    /** Sağlayıcı açık ve gerekli secret'lar tanımlı mı? */
    public function isConfigured(): bool;

    /**
     * Metin mesajı gönderir; sağlayıcı mesaj kimliğini döndürür.
     *
     * @throws \RuntimeException gönderim başarısızsa (kuyruk yeniden dener)
     */
    public function sendText(string $toE164, string $body): string;
}
