<?php

namespace App\Webhooks;

/**
 * Giden webhook olay kaydı (faz 61c). Yeni olay = buraya satır + ilgili serviste `WebhookDispatcher::emit`.
 * Payload'lar kimlik/durum/tutar taşır; kişisel alanlar (ad, e-posta, telefon) gönderilir ama LOG kopyasında
 * maskelenir (WebhookPayload::redact). Panel bu listeden çoklu seçim üretir.
 */
final class WebhookEvents
{
    public const PING = 'ping';

    /** @var array<string, array{label: string, group: string, description: string}> */
    public const REGISTRY = [
        'member.created' => ['label' => 'Üye oluşturuldu', 'group' => 'Üyeler', 'description' => 'Şirkete yeni üye eklendi (davet/hesap).'],
        'member.updated' => ['label' => 'Üye güncellendi', 'group' => 'Üyeler', 'description' => 'Üye profili veya durumu değişti.'],
        'payment.received' => ['label' => 'Ödeme alındı', 'group' => 'Finans', 'description' => 'Faturaya tahsilat kaydedildi (panel veya sağlayıcı webhook\'u).'],
        'invoice.overdue' => ['label' => 'Ödeme gecikti', 'group' => 'Finans', 'description' => 'Vade + tolerans geçti, fatura gecikmiş sayıldı.'],
        'franchise.applied' => ['label' => 'Franchise başvurusu', 'group' => 'Talepler', 'description' => 'Vitrinden franchise başvurusu alındı.'],
        'lead.created' => ['label' => 'Form gönderildi', 'group' => 'Talepler', 'description' => 'Vitrin formu (teklif, iletişim, bülten…) gönderildi.'],
        'content.published' => ['label' => 'Blog / sayfa yayınlandı', 'group' => 'İçerik', 'description' => 'İçerik yayına alındı (zamanlanmış dahil).'],
        'location.created' => ['label' => 'Lokasyon oluşturuldu', 'group' => 'Operasyon', 'description' => 'Yeni şube kaydı açıldı.'],
        'booking.created' => ['label' => 'Rezervasyon oluşturuldu', 'group' => 'Operasyon', 'description' => 'Toplantı odası rezervasyonu oluşturuldu (onay bekleyen dahil).'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::REGISTRY);
    }

    public static function valid(string $event): bool
    {
        return isset(self::REGISTRY[$event]);
    }

    public static function label(string $event): string
    {
        return $event === self::PING ? 'Test (ping)' : (self::REGISTRY[$event]['label'] ?? $event);
    }

    /** @return array<string, array<string, array{label: string, group: string, description: string}>> grup => olaylar */
    public static function grouped(): array
    {
        $out = [];

        foreach (self::REGISTRY as $key => $def) {
            $out[$def['group']][$key] = $def;
        }

        return $out;
    }
}
