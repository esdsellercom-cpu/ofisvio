<?php

namespace App\Integrations;

/**
 * API & Entegrasyon merkezi kataloğu (faz 52): projede GERÇEKTEN kullanılan bağlantılar, kategori bazlı.
 * Sağlayıcı geçidi (config/integrations.php: iyzico, IndexNow, Search Console, Analytics, AI, SMS, WhatsApp,
 * e-Fatura) + çekirdek bağlantılar (veritabanı, önbellek, kuyruk, e-posta, depolama, KYC tarayıcı, zamanlayıcı).
 * Secret'lar yalnız env'dedir (SecretStore); panelde maskeli görünür, forma girilmez, kaydedilmez.
 * Kullanılmayan servis (Mapbox, S3 gibi) burada listelenmez — sahte uç yok.
 */
final class IntegrationCatalog
{
    public const CATEGORIES = [
        'core' => 'Çekirdek (veritabanı, önbellek, kuyruk, zamanlayıcı)',
        'email' => 'E-posta',
        'messaging' => 'SMS & WhatsApp',
        'payment' => 'Ödeme & e-Fatura',
        'ai' => 'AI',
        'seo' => 'SEO & Analytics',
        'storage' => 'Depolama & güvenlik',
        'webhook' => 'Webhook & API',
    ];

    /** Sağlayıcı geçidi anahtarı → kategori. */
    public const PROVIDER_CATEGORY = ['iyzico' => 'payment', 'efatura' => 'payment', 'indexnow' => 'seo', 'search_console' => 'seo', 'analytics' => 'seo', 'pagespeed' => 'seo', 'google_oauth' => 'seo', 'ai' => 'ai', 'sms' => 'messaging', 'whatsapp' => 'messaging'];

    /** Sağlayıcının env anahtarları (panelde "nasıl tanımlanır" için; değer gösterilmez). */
    public const PROVIDER_ENV = [
        'iyzico' => ['IYZICO_ENABLED', 'IYZICO_BASE_URL', 'IYZICO_API_KEY', 'IYZICO_SECRET_KEY', 'IYZICO_WEBHOOK_SECRET'],
        'efatura' => ['EFATURA_ENABLED', 'EFATURA_BASE_URL', 'EFATURA_USERNAME', 'EFATURA_PASSWORD', 'EFATURA_WEBHOOK_SECRET'],
        'indexnow' => ['INDEXNOW_ENABLED', 'INDEXNOW_BASE_URL'],
        'search_console' => ['SEARCH_CONSOLE_ENABLED', 'SEARCH_CONSOLE_SERVICE_ACCOUNT_JSON'],
        'analytics' => ['ANALYTICS_ENABLED', 'ANALYTICS_SERVICE_ACCOUNT_JSON'],
        'pagespeed' => ['PAGESPEED_ENABLED', 'PAGESPEED_API_KEY'],
        'google_oauth' => ['GOOGLE_OAUTH_BASE_URL'],
        'ai' => ['AI_ENABLED', 'AI_BASE_URL', 'AI_API_KEY', 'AI_MODEL', 'AI_PRICE_INPUT_PER_MTOK', 'AI_PRICE_OUTPUT_PER_MTOK'],
        'sms' => ['SMS_ENABLED', 'SMS_BASE_URL', 'SMS_API_KEY', 'SMS_SEND_PATH', 'SMS_WEBHOOK_SECRET'],
        'whatsapp' => ['WHATSAPP_ENABLED', 'WHATSAPP_BASE_URL', 'WHATSAPP_ACCESS_TOKEN', 'WHATSAPP_PHONE_NUMBER_ID', 'WHATSAPP_WEBHOOK_SECRET'],
    ];

    /** Projede kullanılan yer (panel bağlantısı ile). */
    public const PROVIDER_USAGE = [
        'iyzico' => 'Online ödeme (Integration Gateway; webhook /webhooks/iyzico).',
        'efatura' => 'e-Fatura gönderimi (Gateway).',
        'indexnow' => 'İçerik yayınlanınca arama motoru bildirimi (NotifyIndexNowOnContentChange). Anahtar: SEO & GEO › Entegrasyonlar.',
        'search_console' => 'Search Console verisi (Gateway). Doğrulama etiketi: SEO & GEO › Entegrasyonlar.',
        'analytics' => 'Analytics API (Gateway). GA4 / GTM kimlikleri: SEO & GEO › Entegrasyonlar.',
        'pagespeed' => 'Core Web Vitals ölçümü (ofisvio:web-vitals; Performans › Core Web Vitals).',
        'google_oauth' => 'Search Console / Analytics servis hesabı belirteci (otomatik; iki sağlayıcıdan biri açıkken).',
        'ai' => 'AI Content Engine (faz 60e): brief → taslak → doğruluk → SEO → GEO → kopya → inceleme → onay → yayın; Gateway üzerinden, insan onayı zorunlu. Prompt Registry ve maliyet takibi SEO & GEO menüsünde.',
        'sms' => 'Bildirim kanalı SMS (NotificationService › SmsProvider).',
        'whatsapp' => 'Bildirim kanalı WhatsApp (NotificationService › WhatsAppProvider; şablon adı Ayarlar › WhatsApp).',
    ];

    /** Çekirdek bağlantılar (env/config tabanlı; test ConnectionTester ile). */
    public const CORE = [
        'database' => ['category' => 'core', 'label' => 'Veritabanı', 'env' => ['DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']],
        'cache' => ['category' => 'core', 'label' => 'Önbellek', 'env' => ['CACHE_STORE', 'REDIS_HOST', 'REDIS_PASSWORD']],
        'queue' => ['category' => 'core', 'label' => 'Kuyruk / worker', 'env' => ['QUEUE_CONNECTION']],
        'scheduler' => ['category' => 'core', 'label' => 'Zamanlayıcı (cron)', 'env' => []],
        'mail' => ['category' => 'email', 'label' => 'E-posta (SMTP / sağlayıcı)', 'env' => ['MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS']],
        'storage' => ['category' => 'storage', 'label' => 'Dosya depolama', 'env' => ['FILESYSTEM_DISK']],
        'scanner' => ['category' => 'storage', 'label' => 'KYC / yükleme tarayıcısı (ClamAV)', 'env' => ['KYC_SCANNER', 'CLAMAV_ADDRESS']],
        'webhooks' => ['category' => 'webhook', 'label' => 'Gelen webhook uçları (/webhooks/{provider})', 'env' => ['WEBHOOK_TOLERANCE']],
    ];
}
