<?php

namespace App\Integrations;

/**
 * Entegrasyon kayıt defteri (faz 61b): her entegrasyonun kendi şeması — alanlar (tip, zorunlu, gelişmiş), kategori,
 * env eşlemesi, kullanım yeri ve test biçimi. Yeni servis = buraya bir kayıt (+ gerekiyorsa config/integrations.php
 * sağlayıcısı ve adaptör). Ortak/zorla alan yok; her entegrasyon yalnız kendi alanlarını taşır.
 *
 * kind: gateway (config/integrations.php sağlayıcısı, Gateway üzerinden) · core (Laravel config: mail, storage) ·
 * link (başka ekranda yönetilir: CRM → webhooklar, sosyal → GEO varlığı, harita → lokasyon künyesi) ·
 * field type: secret | string | url | int | select | bool · env: alanın env anahtarı (env doluysa salt okunur).
 */
final class IntegrationRegistry
{
    public const CATEGORIES = [
        'payment' => ['label' => 'Ödeme', 'lead' => 'Ödeme sağlayıcıları, banka/POS, e-fatura, ödeme webhook\'ları'],
        'email' => ['label' => 'E-posta', 'lead' => 'SMTP / işlem e-postası sağlayıcısı, bildirim gönderimi'],
        'sms' => ['label' => 'SMS & mesajlaşma', 'lead' => 'SMS ve WhatsApp sağlayıcıları'],
        'maps' => ['label' => 'Harita / konum', 'lead' => 'Harita gömme, geocoding, Places'],
        'analytics' => ['label' => 'Analytics & arama', 'lead' => 'GA4, Tag Manager, Search Console, IndexNow, PageSpeed'],
        'crm' => ['label' => 'CRM', 'lead' => 'CRM ve lead sistemleri (giden webhook ile)'],
        'ai' => ['label' => 'AI', 'lead' => 'AI API, içerik üretimi, SEO/GEO analizleri'],
        'storage' => ['label' => 'Depolama & CDN', 'lead' => 'Nesne depolama, CDN, tarayıcı'],
        'social' => ['label' => 'Sosyal medya', 'lead' => 'Sosyal profiller ve API bağlantıları'],
        'webhook' => ['label' => 'Webhook', 'lead' => 'Gelen ve giden webhook\'lar'],
    ];

    /**
     * @return array<string, array{label: string, category: string, kind: string, description: string, usage: string, fields: list<array{key: string, label: string, type: string, required?: bool, advanced?: bool, env?: string, options?: array<string, string>, help?: string, default?: mixed}>, test?: string, link?: string}>
     */
    public static function definitions(): array
    {
        return [
            'iyzico' => ['label' => 'iyzico', 'category' => 'payment', 'kind' => 'gateway', 'description' => 'Online kart ödemesi (Integration Gateway).', 'usage' => 'Ödeme başlatma/doğrulama; webhook /webhooks/iyzico.', 'test' => 'gateway', 'fields' => [
                ['key' => 'api_key', 'label' => 'API anahtarı', 'type' => 'secret', 'required' => true, 'env' => 'IYZICO_API_KEY'],
                ['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'secret', 'required' => true, 'env' => 'IYZICO_SECRET_KEY'],
                ['key' => 'webhook_secret', 'label' => 'Webhook secret', 'type' => 'secret', 'advanced' => true, 'env' => 'IYZICO_WEBHOOK_SECRET', 'help' => 'Gelen webhook imzası (HMAC).'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'url', 'advanced' => true, 'env' => 'IYZICO_BASE_URL', 'default' => 'https://api.iyzipay.com'],
            ]],
            'efatura' => ['label' => 'e-Fatura', 'category' => 'payment', 'kind' => 'gateway', 'description' => 'e-Fatura entegratörü.', 'usage' => 'Fatura gönderimi (Gateway).', 'test' => 'gateway', 'fields' => [
                ['key' => 'username', 'label' => 'Kullanıcı adı', 'type' => 'string', 'required' => true, 'env' => 'EFATURA_USERNAME'],
                ['key' => 'password', 'label' => 'Parola', 'type' => 'secret', 'required' => true, 'env' => 'EFATURA_PASSWORD'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'url', 'required' => true, 'env' => 'EFATURA_BASE_URL'],
                ['key' => 'webhook_secret', 'label' => 'Webhook secret', 'type' => 'secret', 'advanced' => true, 'env' => 'EFATURA_WEBHOOK_SECRET'],
            ]],
            'mail' => ['label' => 'SMTP / e-posta', 'category' => 'email', 'kind' => 'core', 'description' => 'İşlem e-postaları (davet, şifre, bildirimler).', 'usage' => 'MAIL_* env ya da buradaki SMTP ayarı; bildirim kanalı e-posta.', 'test' => 'mail', 'fields' => [
                ['key' => 'host', 'label' => 'SMTP host', 'type' => 'string', 'required' => true, 'env' => 'MAIL_HOST'],
                ['key' => 'port', 'label' => 'Port', 'type' => 'int', 'required' => true, 'env' => 'MAIL_PORT', 'default' => 587],
                ['key' => 'username', 'label' => 'Kullanıcı adı', 'type' => 'string', 'env' => 'MAIL_USERNAME'],
                ['key' => 'password', 'label' => 'Parola', 'type' => 'secret', 'env' => 'MAIL_PASSWORD'],
                ['key' => 'encryption', 'label' => 'Şifreleme', 'type' => 'select', 'options' => ['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => 'Yok'], 'env' => 'MAIL_ENCRYPTION', 'default' => 'tls'],
                ['key' => 'from_name', 'label' => 'Gönderen adı', 'type' => 'string', 'env' => 'MAIL_FROM_NAME'],
                ['key' => 'from_address', 'label' => 'Gönderen e-posta', 'type' => 'string', 'required' => true, 'env' => 'MAIL_FROM_ADDRESS'],
            ]],
            'sms' => ['label' => 'SMS sağlayıcısı', 'category' => 'sms', 'kind' => 'gateway', 'description' => 'HTTP tabanlı SMS gönderimi.', 'usage' => 'Bildirim kanalı SMS (GatewaySmsAdapter).', 'test' => 'gateway', 'fields' => [
                ['key' => 'api_key', 'label' => 'API anahtarı', 'type' => 'secret', 'required' => true, 'env' => 'SMS_API_KEY'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'url', 'required' => true, 'env' => 'SMS_BASE_URL'],
                ['key' => 'send_path', 'label' => 'Gönderim yolu', 'type' => 'string', 'advanced' => true, 'env' => 'SMS_SEND_PATH', 'default' => '/messages'],
                ['key' => 'webhook_secret', 'label' => 'Webhook secret', 'type' => 'secret', 'advanced' => true, 'env' => 'SMS_WEBHOOK_SECRET'],
            ]],
            'whatsapp' => ['label' => 'WhatsApp (Meta Cloud API)', 'category' => 'sms', 'kind' => 'gateway', 'description' => 'WhatsApp işletme mesajları.', 'usage' => 'Bildirim kanalı WhatsApp; şablon adı Ayarlar › WhatsApp.', 'test' => 'gateway', 'fields' => [
                ['key' => 'access_token', 'label' => 'Erişim belirteci', 'type' => 'secret', 'required' => true, 'env' => 'WHATSAPP_ACCESS_TOKEN'],
                ['key' => 'phone_number_id', 'label' => 'Telefon numarası kimliği', 'type' => 'string', 'required' => true, 'env' => 'WHATSAPP_PHONE_NUMBER_ID'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'url', 'advanced' => true, 'env' => 'WHATSAPP_BASE_URL', 'default' => 'https://graph.facebook.com/v20.0'],
                ['key' => 'webhook_secret', 'label' => 'Webhook secret', 'type' => 'secret', 'advanced' => true, 'env' => 'WHATSAPP_WEBHOOK_SECRET'],
            ]],
            'google_maps' => ['label' => 'Google Maps Platform', 'category' => 'maps', 'kind' => 'gateway', 'description' => 'Geocoding / Places (isteğe bağlı; harita gömme OpenStreetMap ile anahtarsız çalışır).', 'usage' => 'Lokasyon künyesinde koordinat doğrulama (Gateway).', 'test' => 'gateway', 'fields' => [
                ['key' => 'api_key', 'label' => 'API anahtarı', 'type' => 'secret', 'required' => true, 'env' => 'GOOGLE_MAPS_API_KEY'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'url', 'advanced' => true, 'env' => 'GOOGLE_MAPS_BASE_URL', 'default' => 'https://maps.googleapis.com'],
            ]],
            'analytics' => ['label' => 'Google Analytics 4 (Data API)', 'category' => 'analytics', 'kind' => 'gateway', 'description' => 'Organik trafik raporları.', 'usage' => 'SEO & GEO › Analytics; mülk kimliği Doğrulama & bildirim sekmesinde.', 'test' => 'google', 'fields' => [
                ['key' => 'service_account_json', 'label' => 'Servis hesabı JSON', 'type' => 'secret', 'required' => true, 'env' => 'ANALYTICS_SERVICE_ACCOUNT_JSON', 'help' => 'JSON metni ya da sunucudaki dosya yolu.'],
            ]],
            'search_console' => ['label' => 'Google Search Console', 'category' => 'analytics', 'kind' => 'gateway', 'description' => 'Tıklama/gösterim/sıra verisi.', 'usage' => 'SEO & GEO › Search Console.', 'test' => 'google', 'fields' => [
                ['key' => 'service_account_json', 'label' => 'Servis hesabı JSON', 'type' => 'secret', 'required' => true, 'env' => 'SEARCH_CONSOLE_SERVICE_ACCOUNT_JSON', 'help' => 'Servis hesabı e-postası mülke eklenmeli.'],
            ]],
            'pagespeed' => ['label' => 'PageSpeed Insights', 'category' => 'analytics', 'kind' => 'gateway', 'description' => 'Core Web Vitals ölçümü.', 'usage' => 'Performans › Core Web Vitals.', 'test' => 'gateway', 'fields' => [
                ['key' => 'api_key', 'label' => 'API anahtarı (isteğe bağlı)', 'type' => 'secret', 'env' => 'PAGESPEED_API_KEY', 'help' => 'Kota artırır; boşken anonim kota.'],
            ]],
            'indexnow' => ['label' => 'IndexNow', 'category' => 'analytics', 'kind' => 'gateway', 'description' => 'Bing/Yandex/Naver anlık indeks bildirimi.', 'usage' => 'İçerik yayınlanınca otomatik; anahtar SEO & GEO › Doğrulama & bildirim.', 'test' => 'gateway', 'fields' => [
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'url', 'advanced' => true, 'env' => 'INDEXNOW_BASE_URL', 'default' => 'https://api.indexnow.org'],
            ]],
            'ai' => ['label' => 'AI sağlayıcısı (Anthropic)', 'category' => 'ai', 'kind' => 'gateway', 'description' => 'AI Content Engine: taslak, doğruluk, yenileme.', 'usage' => 'SEO & GEO › AI Content; prompt sürümleri Prompt Registry.', 'test' => 'ai', 'fields' => [
                ['key' => 'api_key', 'label' => 'API anahtarı', 'type' => 'secret', 'required' => true, 'env' => 'AI_API_KEY'],
                ['key' => 'model', 'label' => 'Model', 'type' => 'string', 'env' => 'AI_MODEL', 'default' => 'claude-sonnet-5'],
                ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'url', 'advanced' => true, 'env' => 'AI_BASE_URL', 'default' => 'https://api.anthropic.com'],
                ['key' => 'timeout', 'label' => 'Zaman aşımı (sn)', 'type' => 'int', 'advanced' => true, 'default' => 120],
                ['key' => 'price_input_per_mtok', 'label' => 'Girdi fiyatı (1M token)', 'type' => 'string', 'advanced' => true, 'env' => 'AI_PRICE_INPUT_PER_MTOK', 'help' => 'Boşsa maliyet hesaplanmaz.'],
                ['key' => 'price_output_per_mtok', 'label' => 'Çıktı fiyatı (1M token)', 'type' => 'string', 'advanced' => true, 'env' => 'AI_PRICE_OUTPUT_PER_MTOK'],
            ]],
            'storage' => ['label' => 'Nesne depolama (S3 uyumlu)', 'category' => 'storage', 'kind' => 'core', 'description' => 'Medya/belge depolama için S3 uyumlu disk.', 'usage' => 'FILESYSTEM_DISK=s3 seçildiğinde bu bağlantı kullanılır.', 'test' => 'storage', 'fields' => [
                ['key' => 'key', 'label' => 'Access key', 'type' => 'string', 'required' => true, 'env' => 'AWS_ACCESS_KEY_ID'],
                ['key' => 'secret', 'label' => 'Secret key', 'type' => 'secret', 'required' => true, 'env' => 'AWS_SECRET_ACCESS_KEY'],
                ['key' => 'bucket', 'label' => 'Bucket', 'type' => 'string', 'required' => true, 'env' => 'AWS_BUCKET'],
                ['key' => 'region', 'label' => 'Bölge', 'type' => 'string', 'required' => true, 'env' => 'AWS_DEFAULT_REGION', 'default' => 'eu-central-1'],
                ['key' => 'endpoint', 'label' => 'Endpoint (S3 uyumlu)', 'type' => 'url', 'advanced' => true, 'env' => 'AWS_ENDPOINT'],
                ['key' => 'url', 'label' => 'Genel URL / CDN', 'type' => 'url', 'advanced' => true, 'env' => 'AWS_URL', 'help' => 'CDN önünde yayınlıyorsanız CDN adresi.'],
            ]],
            'crm' => ['label' => 'CRM / lead sistemi', 'category' => 'crm', 'kind' => 'link', 'description' => 'Yeni talep, üye, ödeme olayları giden webhook ile CRM\'e akar.', 'usage' => 'Webhook merkezi: olay seç → CRM uç noktası.', 'fields' => [], 'link' => 'webhooks'],
            'social' => ['label' => 'Sosyal medya profilleri', 'category' => 'social', 'kind' => 'link', 'description' => 'Profil bağlantıları header/footer\'da ve Organization sameAs şemasında.', 'usage' => 'Header & footer ayarları · GEO varlık (sameAs).', 'fields' => [], 'link' => 'chrome'],
            'webhook_in' => ['label' => 'Gelen webhook\'lar', 'category' => 'webhook', 'kind' => 'link', 'description' => 'POST /webhooks/{provider}: HMAC imza, zaman damgası, tekil olay kimliği; sağlayıcı webhook secret\'ı ilgili entegrasyonda.', 'usage' => 'iyzico / e-fatura / sms / whatsapp secret alanları.', 'fields' => [], 'link' => 'webhooks'],
            'webhook_out' => ['label' => 'Giden webhook\'lar', 'category' => 'webhook', 'kind' => 'link', 'description' => 'Sistem olaylarını dış uç noktalara imzalı POST ile bildirir; yeniden deneme + log.', 'usage' => 'Webhook merkezi.', 'fields' => [], 'link' => 'webhooks'],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function definition(string $key): ?array
    {
        return self::definitions()[$key] ?? null;
    }

    /** Secret alan anahtarları. @return list<string> */
    public static function secretFields(string $key): array
    {
        return array_values(array_map(fn (array $f) => $f['key'], array_filter(self::definition($key)['fields'] ?? [], fn (array $f) => $f['type'] === 'secret')));
    }
}
