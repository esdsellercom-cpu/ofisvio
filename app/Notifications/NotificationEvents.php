<?php

namespace App\Notifications;

/**
 * Bildirim olayları kaydı (master prompt §16–17). Her olay: etiket, yer tutucular,
 * varsayılan alıcı grupları ve TEKNİK varsayılan şablon (DB'de şablon yoksa).
 * Şablonlar ticari veri değildir; ama panelden (notification_templates) ezilir.
 *
 * Yer tutucu sözdizimi: {{anahtar}} — NotificationService::render, bilinmeyen anahtarı boş basar.
 */
final class NotificationEvents
{
    public const CHANNELS = ['in_app' => 'Uygulama içi', 'email' => 'E-posta', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];

    public const GROUPS = [
        'super_admin' => 'Süper yöneticiler',
        'booking_managers' => 'Rezervasyon yöneticileri',
        'location_managers' => 'Lokasyon yöneticileri',
        'finance' => 'Finans',
        'crm' => 'CRM',
        'compliance' => 'Uyum',
        'custom' => 'Özel alıcı',
        'customer' => 'Müşteri (olayın muhatabı)',
    ];

    /** Müşteri grubu: alıcı listesinden değil, olayın yükünden (customer_email/phone) çözülür. */
    public const CUSTOMER_GROUP = 'customer';

    /**
     * @return array<string, array{label: string, placeholders: array<int, string>, defaults: array<int, array{0: string, 1: string}>, subject: string, body: string}>
     */
    public static function registry(): array
    {
        $bookingPlaceholders = ['reference', 'customer_name', 'customer_phone', 'customer_email', 'company_name', 'location', 'room', 'date', 'time_range', 'participants', 'status', 'amount', 'note', 'reason', 'brand'];
        $bookingBody = "{{brand}} — {{title}}\n\nRezervasyon: #{{reference}}\nMüşteri: {{customer_name}}\nTelefon: {{customer_phone}}\nFirma: {{company_name}}\nLokasyon: {{location}}\nOda: {{room}}\nTarih: {{date}}\nSaat: {{time_range}}\nKişi: {{participants}}\n\nDurum: {{status}}";

        return [
            'booking.requested' => ['label' => 'Yeni rezervasyon talebi', 'placeholders' => $bookingPlaceholders, 'defaults' => [['in_app', 'booking_managers'], ['email', 'booking_managers'], ['whatsapp', 'booking_managers'], ['email', 'customer']], 'subject' => '{{brand}} — Yeni randevu #{{reference}}', 'body' => str_replace('{{title}}', 'Yeni Randevu', $bookingBody)."\n\nYeni bir toplantı odası rezervasyon talebi oluşturuldu; yönetici onayı bekliyor."],
            'booking.confirmed' => ['label' => 'Rezervasyon onaylandı', 'placeholders' => $bookingPlaceholders, 'defaults' => [['email', 'customer'], ['whatsapp', 'booking_managers'], ['in_app', 'booking_managers']], 'subject' => '{{brand}} — Rezervasyonunuz onaylandı #{{reference}}', 'body' => str_replace('{{title}}', 'Rezervasyon Onaylandı', $bookingBody)."\n\nRezervasyonunuz onaylandı; belirtilen saatte lokasyon resepsiyonuna gelmeniz yeterlidir."],
            'booking.rejected' => ['label' => 'Rezervasyon reddedildi', 'placeholders' => $bookingPlaceholders, 'defaults' => [['email', 'customer'], ['in_app', 'booking_managers']], 'subject' => '{{brand}} — Rezervasyon talebi #{{reference}}', 'body' => str_replace('{{title}}', 'Rezervasyon Reddedildi', $bookingBody)."\n\nGerekçe: {{reason}}"],
            'booking.cancelled' => ['label' => 'Rezervasyon iptal edildi', 'placeholders' => $bookingPlaceholders, 'defaults' => [['email', 'customer'], ['in_app', 'booking_managers'], ['whatsapp', 'booking_managers']], 'subject' => '{{brand}} — Rezervasyon iptal #{{reference}}', 'body' => str_replace('{{title}}', 'Rezervasyon İptal', $bookingBody)."\n\nGerekçe: {{reason}}"],
            'booking.expired' => ['label' => 'Talep süresi doldu', 'placeholders' => $bookingPlaceholders, 'defaults' => [['in_app', 'booking_managers']], 'subject' => '{{brand}} — Talep süresi doldu #{{reference}}', 'body' => str_replace('{{title}}', 'Talep Süresi Doldu', $bookingBody)."\n\nOnaylanmadığı için talep süresi doldu; saat serbest bırakıldı."],
            'lead.created' => ['label' => 'Yeni talep (form)', 'placeholders' => ['name', 'email', 'phone', 'kind', 'solution', 'location', 'brand'], 'defaults' => [['in_app', 'crm'], ['email', 'crm']], 'subject' => '{{brand}} — Yeni form talebi', 'body' => "{{brand}} — Yeni Talep\n\nAd: {{name}}\nE-posta: {{email}}\nTelefon: {{phone}}\nTür: {{kind}}\nÇözüm: {{solution}}\nLokasyon: {{location}}"],
            'kyc.submitted' => ['label' => 'Yeni KYC belgesi', 'placeholders' => ['company', 'document', 'brand'], 'defaults' => [['in_app', 'compliance']], 'subject' => '{{brand}} — Yeni KYC belgesi', 'body' => "{{brand}} — KYC\n\nŞirket: {{company}}\nBelge: {{document}}\nİnceleme bekliyor."],
            'notification.failed' => ['label' => 'Bildirim gönderilemedi (sistem)', 'placeholders' => ['event', 'channel', 'recipient', 'error', 'brand'], 'defaults' => [['in_app', 'super_admin']], 'subject' => '{{brand}} — Bildirim gönderilemedi', 'body' => "{{brand}} — Bildirim gönderilemedi\n\nOlay: {{event}}\nKanal: {{channel}}\nAlıcı: {{recipient}}\nHata: {{error}}\n\nTüm denemeler tükendi; Bildirim Merkezi › Günlük."],
            'security.alert' => ['label' => 'Güvenlik uyarısı', 'placeholders' => ['message', 'brand'], 'defaults' => [['in_app', 'super_admin'], ['email', 'super_admin']], 'subject' => '{{brand}} — Güvenlik uyarısı', 'body' => "{{brand}} — Güvenlik\n\n{{message}}"],
        ];
    }

    /** @return array{label: string, placeholders: array<int, string>, defaults: array<int, array{0: string, 1: string}>, subject: string, body: string} */
    public static function definition(string $event): array
    {
        $all = self::registry();

        if (! isset($all[$event])) {
            throw new \InvalidArgumentException("Tanımsız bildirim olayı: {$event}");
        }

        return $all[$event];
    }

    public static function exists(string $event): bool
    {
        return isset(self::registry()[$event]);
    }
}
