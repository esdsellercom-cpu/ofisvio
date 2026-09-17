<?php

namespace App\Settings;

/**
 * Ayar tanımları (master prompt §33): her anahtarın tipi, varsayılanı, doğrulaması,
 * izin verilen kapsamları, grubu ve açıklaması KODDA; değeri veritabanında
 * (`settings`). Yeni ayar = buraya bir satır; panel formu ve doğrulama buradan türer.
 *
 * Kapsam kalıtımı (SettingsService::get): location > company > organization > installation > default.
 * Varsayılanlar TEKNİK sabittir (süre, sınır); ticari veri (telefon, fiyat) varsayılan taşımaz.
 */
final class SettingsRegistry
{
    public const SCOPES = ['installation', 'organization', 'company', 'location'];

    public const GROUPS = [
        'booking' => 'Rezervasyon',
        'notifications' => 'Bildirimler',
        'whatsapp' => 'WhatsApp',
        'general' => 'Genel',
    ];

    /**
     * @return array<string, array{group: string, label: string, type: string, default: mixed, rules: array<int, string>, scopes: array<int, string>, description: string, options?: array<string, string>, secret?: bool}>
     */
    public static function definitions(): array
    {
        return [
            // --- Rezervasyon: onay politikası + uygunluk kuralları (§8, §11) ---
            'booking.auto_confirm' => ['group' => 'booking', 'label' => 'Otomatik onay', 'type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'scopes' => ['installation', 'location'], 'description' => 'Kapalıysa her talep yönetici onayı bekler (PENDING_APPROVAL); açıksa uygun talep anında CONFIRMED olur.'],
            'booking.confirmation_sla' => ['group' => 'booking', 'label' => 'Teyit taahhüdü (vitrin rozeti)', 'type' => 'select', 'default' => 'same_day', 'rules' => ['in:same_day,two_hours,next_business_day'], 'scopes' => ['installation', 'location'], 'description' => 'Vitrindeki "aynı gün teyit" metni bu ayardan türetilir.', 'options' => ['same_day' => 'Aynı gün teyit', 'two_hours' => '2 saat içinde teyit', 'next_business_day' => 'Bir sonraki iş günü teyit']],
            'booking.min_advance_hours' => ['group' => 'booking', 'label' => 'En az önceden (saat)', 'type' => 'int', 'default' => 2, 'rules' => ['integer', 'min:0', 'max:168'], 'scopes' => ['installation', 'location'], 'description' => 'Başlangıca bu kadar saatten az kala talep alınmaz.'],
            'booking.max_advance_days' => ['group' => 'booking', 'label' => 'En fazla ileri (gün)', 'type' => 'int', 'default' => 60, 'rules' => ['integer', 'min:1', 'max:365'], 'scopes' => ['installation', 'location'], 'description' => 'Rezervasyon ufku.'],
            'booking.buffer_minutes' => ['group' => 'booking', 'label' => 'Tampon süre (dk)', 'type' => 'int', 'default' => 0, 'rules' => ['integer', 'min:0', 'max:120'], 'scopes' => ['installation', 'location'], 'description' => 'İki rezervasyon arasında boş bırakılan süre (hazırlık/temizlik).'],
            'booking.cancel_notice_hours' => ['group' => 'booking', 'label' => 'Müşteri iptali için son süre (saat)', 'type' => 'int', 'default' => 2, 'rules' => ['integer', 'min:0', 'max:168'], 'scopes' => ['installation', 'location'], 'description' => 'Müşteri başlangıca bu kadar saatten az kala iptal edemez; resepsiyon/JIT edebilir.'],
            'booking.request_expires_hours' => ['group' => 'booking', 'label' => 'Onaysız talep süresi (saat)', 'type' => 'int', 'default' => 24, 'rules' => ['integer', 'min:1', 'max:720'], 'scopes' => ['installation'], 'description' => 'Bu süre içinde onaylanmayan talep EXPIRED olur ve saati serbest bırakır (zamanlayıcı).'],
            'booking.reference_prefix' => ['group' => 'booking', 'label' => 'Rezervasyon numarası öneki', 'type' => 'string', 'default' => 'OV', 'rules' => ['string', 'regex:/^[A-Z]{2,5}$/'], 'scopes' => ['installation'], 'description' => 'Örn. OV-2026-000124.'],

            // --- Bildirimler (§16–19) ---
            'notifications.admin_alert_on_failure' => ['group' => 'notifications', 'label' => 'Gönderim başarısızlığında yönetici uyarısı', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'scopes' => ['installation'], 'description' => 'Tüm denemeler tükenince süper yöneticilere uygulama içi uyarı düşer.'],
            'notifications.max_attempts' => ['group' => 'notifications', 'label' => 'En fazla deneme', 'type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:1', 'max:10'], 'scopes' => ['installation'], 'description' => 'Kuyruk yeniden deneme sayısı (üstel geri çekilme).'],
            'notifications.customer_email_enabled' => ['group' => 'notifications', 'label' => 'Müşteriye e-posta', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'scopes' => ['installation'], 'description' => 'Onay/red/iptal sonucu müşteriye e-posta ile bildirilir.'],

            // --- WhatsApp (§12–15): sağlayıcı env/config; alıcılar Bildirim Merkezi'nde ---
            'whatsapp.sender_label' => ['group' => 'whatsapp', 'label' => 'Gönderen etiketi', 'type' => 'string', 'default' => 'OFISVIO', 'rules' => ['string', 'max:40'], 'scopes' => ['installation'], 'description' => 'Mesaj başlığında kullanılan marka etiketi.'],
            'whatsapp.template_name' => ['group' => 'whatsapp', 'label' => 'Onaylı şablon adı', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:80', 'regex:/^[a-z0-9_]*$/'], 'scopes' => ['installation'], 'description' => 'Meta Cloud API: 24 saatlik pencere dışında işletme mesajı onaylı şablon ister (tek gövde parametresi). Boşsa düz metin gönderilir.'],
            'whatsapp.template_locale' => ['group' => 'whatsapp', 'label' => 'Şablon dili', 'type' => 'select', 'default' => 'tr', 'rules' => ['in:tr,en'], 'scopes' => ['installation'], 'description' => 'Sağlayıcıya gönderilen şablon dili.', 'options' => ['tr' => 'Türkçe', 'en' => 'İngilizce']],

            // --- Genel ---
            'general.timezone' => ['group' => 'general', 'label' => 'Saat dilimi', 'type' => 'string', 'default' => 'Europe/Istanbul', 'rules' => ['string', 'timezone:all'], 'scopes' => ['installation'], 'description' => 'Rezervasyon saatleri bu dilimde yorumlanır.'],
            'general.currency' => ['group' => 'general', 'label' => 'Para birimi', 'type' => 'select', 'default' => 'TRY', 'rules' => ['in:TRY,EUR,USD'], 'scopes' => ['installation'], 'description' => 'Tutar gösterimi.', 'options' => ['TRY' => '₺ Türk lirası', 'EUR' => '€ Euro', 'USD' => '$ ABD doları']],
        ];
    }

    /** @return array{group: string, label: string, type: string, default: mixed, rules: array<int, string>, scopes: array<int, string>, description: string, options?: array<string, string>, secret?: bool} */
    public static function definition(string $key): array
    {
        $defs = self::definitions();

        if (! isset($defs[$key])) {
            throw new \InvalidArgumentException("Tanımsız ayar: {$key}");
        }

        return $defs[$key];
    }

    public static function exists(string $key): bool
    {
        return isset(self::definitions()[$key]);
    }
}
