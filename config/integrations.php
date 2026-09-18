<?php

/*
 * Entegrasyon geçidi (faz 5 / §66 "Integration Gateway").
 *
 * Kural: frontend ve uygulama kodu dış sağlayıcıya DOĞRUDAN bağlanmaz; tek çıkış
 * App\Integrations\Gateway'dir (ArchitectureTest zorlar). Kimlik bilgileri YALNIZ
 * env'den okunur, veritabanında/kodda tutulmaz, loglanmaz, panelde maskeli görünür.
 * Her sağlayıcı varsayılan olarak KAPALI: env ile açılır, açıkken secret yoksa
 * doctor hata verir, geçit isteği reddeder.
 *
 * base_url https zorunlu; SSRF koruması (UrlGuard) yalnız buradaki ana bilgisayara
 * ve genel IP'lere izin verir.
 */
return [
    'timeout_seconds' => (int) env('INTEGRATION_TIMEOUT', 10),

    /** Webhook imza zaman damgası toleransı (saniye) — replay savunması. */
    'webhook_tolerance_seconds' => (int) env('WEBHOOK_TOLERANCE', 300),

    'providers' => [
        'iyzico' => [
            'label' => 'iyzico (ödeme)',
            'enabled' => (bool) env('IYZICO_ENABLED', false),
            'base_url' => env('IYZICO_BASE_URL', 'https://api.iyzipay.com'),
            'secrets' => ['api_key' => env('IYZICO_API_KEY'), 'secret_key' => env('IYZICO_SECRET_KEY')],
            'webhook_secret' => env('IYZICO_WEBHOOK_SECRET'),
        ],
        // IndexNow (faz 44): anahtar site ayarında (panel), secret yok; açma/kapama env.
        'indexnow' => [
            'label' => 'IndexNow (Bing/Yandex/Naver bildirimi)',
            'enabled' => (bool) env('INDEXNOW_ENABLED', false),
            'base_url' => env('INDEXNOW_BASE_URL', 'https://api.indexnow.org'),
            'secrets' => [],
            'webhook_secret' => null,
        ],
        // Google servis hesabı token ucu (faz 60d): Search Console / Analytics servis hesabı JWT'sini erişim
        // belirtecine çevirir. Secret taşımaz; iki sağlayıcıdan biri açıksa açık.
        'google_oauth' => [
            'label' => 'Google OAuth (servis hesabı)',
            'enabled' => (bool) env('SEARCH_CONSOLE_ENABLED', false) || (bool) env('ANALYTICS_ENABLED', false),
            'base_url' => env('GOOGLE_OAUTH_BASE_URL', 'https://oauth2.googleapis.com'),
            'secrets' => [],
            'webhook_secret' => null,
        ],
        // PageSpeed Insights (faz 60d): Core Web Vitals lab + alan verisi. Anahtar isteğe bağlı (kotayı artırır).
        'pagespeed' => [
            'label' => 'PageSpeed Insights (Core Web Vitals)',
            'enabled' => (bool) env('PAGESPEED_ENABLED', false),
            'base_url' => env('PAGESPEED_BASE_URL', 'https://www.googleapis.com'),
            'secrets' => [],
            'api_key' => env('PAGESPEED_API_KEY'),
            'webhook_secret' => null,
        ],
        // Google Maps Platform (faz 61b): geocoding/places — isteğe bağlı; harita gömme OpenStreetMap ile anahtarsız.
        'google_maps' => [
            'label' => 'Google Maps Platform',
            'enabled' => (bool) env('GOOGLE_MAPS_ENABLED', false),
            'base_url' => env('GOOGLE_MAPS_BASE_URL', 'https://maps.googleapis.com'),
            'secrets' => ['api_key' => env('GOOGLE_MAPS_API_KEY')],
            'webhook_secret' => null,
        ],
        'search_console' => [
            'label' => 'Google Search Console',
            'enabled' => (bool) env('SEARCH_CONSOLE_ENABLED', false),
            'base_url' => env('SEARCH_CONSOLE_BASE_URL', 'https://searchconsole.googleapis.com'),
            'secrets' => ['service_account_json' => env('SEARCH_CONSOLE_SERVICE_ACCOUNT_JSON')],
            'webhook_secret' => null,
        ],
        'analytics' => [
            'label' => 'Analytics',
            'enabled' => (bool) env('ANALYTICS_ENABLED', false),
            'base_url' => env('ANALYTICS_BASE_URL', 'https://analyticsdata.googleapis.com'),
            'secrets' => ['service_account_json' => env('ANALYTICS_SERVICE_ACCOUNT_JSON')],
            'webhook_secret' => null,
        ],
        'ai' => [
            'label' => 'AI sağlayıcısı',
            'enabled' => (bool) env('AI_ENABLED', false),
            'base_url' => env('AI_BASE_URL', 'https://api.anthropic.com'),
            'secrets' => ['api_key' => env('AI_API_KEY')],
            'webhook_secret' => null,
            // Faz 60e: model + API sürümü; fiyat isteğe bağlı (tanımlıysa iş kaydına maliyet yazılır, yoksa yalnız token).
            'model' => env('AI_MODEL', 'claude-sonnet-5'),
            'version' => env('AI_API_VERSION', '2023-06-01'),
            'price_input_per_mtok' => env('AI_PRICE_INPUT_PER_MTOK'),
            'price_output_per_mtok' => env('AI_PRICE_OUTPUT_PER_MTOK'),
            'price_currency' => env('AI_PRICE_CURRENCY', 'USD'),
        ],
        'sms' => [
            'label' => 'SMS',
            'enabled' => (bool) env('SMS_ENABLED', false),
            'base_url' => env('SMS_BASE_URL'),
            'secrets' => ['api_key' => env('SMS_API_KEY')],
            'send_path' => env('SMS_SEND_PATH', '/messages'),
            'webhook_secret' => env('SMS_WEBHOOK_SECRET'),
        ],
        'whatsapp' => [
            'label' => 'WhatsApp (Meta Cloud API)',
            'enabled' => (bool) env('WHATSAPP_ENABLED', false),
            'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com/v20.0'),
            'secrets' => ['access_token' => env('WHATSAPP_ACCESS_TOKEN'), 'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID')],
            'webhook_secret' => env('WHATSAPP_WEBHOOK_SECRET'),
        ],
        'efatura' => [
            'label' => 'e-Fatura',
            'enabled' => (bool) env('EFATURA_ENABLED', false),
            'base_url' => env('EFATURA_BASE_URL'),
            'secrets' => ['username' => env('EFATURA_USERNAME'), 'password' => env('EFATURA_PASSWORD')],
            'webhook_secret' => env('EFATURA_WEBHOOK_SECRET'),
        ],
    ],
];
