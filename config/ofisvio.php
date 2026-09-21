<?php

/**
 * Vitrin içeriği.
 *
 * NEDEN CONFIG? Bu metinler pazarlamanın değiştirdiği, geliştiricinin
 * değiştirmediği içeriktir; Blade şablonlarının içine gömülürse her düzeltme
 * bir deploy gerektirir. Uzun vadede CMS modülüne (matriste `cms`) taşınacak;
 * o zamana kadar tek dosyada durur.
 *
 * LOKASYONLAR BURADA DEĞİLDİR: onlar veritabanından gelir (locations tablosu),
 * çünkü lokasyon operasyonel bir varlıktır — resepsiyon rolü location
 * kapsamlıdır, ziyaretçi ve kargo kayıtları oraya bağlanır.
 */

return [

    /*
     * KYC belge karantinası (faz 5). Yüklenen her belge PENDING'e düşmeden
     * önce taranır; enfekte -> QUARANTINED, tarama yapılamıyor -> yükleme
     * reddedilir (fail-closed).
     *
     *   scanner: 'clamav' (üretim) | 'none' (YALNIZCA geliştirme; production'da
     *            SecurityServiceProvider açılışta reddeder)
     */
    /*
     * Site temaları (faz 10): anahtar = html[data-theme], token seti ofisvio.css'te.
     * Yeni tema = CSS'e bir blok + buraya bir satır; derleme adımı yok.
     */
    /*
     * Ana sayfa metinleri (faz 29): vitrin bloğu 'texts' ile panelden ezilir
     * (SiteBlockService::TEXT_KEYS). Burası kod varsayılanıdır.
     */
    'texts' => [
        'topbar' => 'Tek sözleşmeyle hepsine erişim',
        'topbar_count' => '{count} lokasyon',
        'hero_eyebrow' => 'Sanal ofis · Hazır ofis · Coworking',
        'hero_title' => 'Şirketinizin adresi',
        'hero_accent' => 'bugün',
        'hero_title_after' => 'hazır olsun.',
        'hero_lede' => 'Tescile uygun adres, karşılanan çağrılar ve evraklar, dakikası hesap edilmiş toplantı odaları. Tek sözleşme, tüm lokasyonlara erişim.',
        'hero_match' => '',
        'solutions_lede' => 'Hepsi aynı altyapıyı paylaşır: resepsiyon, fiber, evrak ve kargo karşılama, şubeler arası geçiş hakkı dahildir.',
        'solutions_title' => 'Çalışma biçiminize göre dört başlangıç noktası',
        'journey_title' => 'Adresiniz altı adımda tescile hazır',
        'journey_lede' => 'Her adımın durumunu panelinizden canlı görürsünüz. Belgeleriniz incelenirken nerede olduğunuzu tahmin etmeniz gerekmez.',
        'locations_title' => 'İşin merkezinde, metroya yürüme mesafesinde',
        'locations_blurb' => '',
        'meeting_title' => 'Saatlik toplantı odası, aynı gün teyitli',
        'meeting_lede' => '4 kişilik görüşme odasından 120 kişilik etkinlik katına kadar. Ekran, beyaz tahta, ikram ve teknik ekip fiyata dahil; üyelere aylık kredi tanımlanır.',
        'amenities_title' => 'Her katta olması gerekenler, ek fatura olmadan',
        'pricing_title' => 'Şeffaf karşılaştırma',
        'stats_review_time' => 'Aynı gün',
        'nav_solutions' => 'Çözümler',
        'nav_journey' => 'Nasıl çalışır',
        'nav_locations' => 'Lokasyonlar',
        'nav_meeting' => 'Toplantı & Etkinlik',
        'nav_pricing' => 'Üyelikler',
        'cta_header' => 'Teklif Al',
        'cta_topbar' => 'Yerinde tur planla',
        'cta_hero' => 'Uygunluk gör',
        'cta_solution' => 'Teklif al →',
        'lead_title' => 'Formu bırakın, aynı iş günü içinde fiyat gelsin',
        'lead_lede' => 'Ekip büyüklüğünüze göre kat planı, tescil için gereken belge listesi ve net aylık maliyet tek e-postada. Pazarlama listesine eklenmezsiniz.',
        'lead_claim_1' => 'Sözleşme öncesi ödeme yok',
        'lead_claim_2' => 'Sözleşme süresi 1 aydan başlar',
        'lead_claim_3' => 'Belge inceleme aynı iş günü içinde',
        'booking_widget_title' => 'Hızlı ön talep',
        'blog_title' => 'Güncel İçerikler',
        'blog_lede' => 'İş dünyası, ofis çözümleri ve çalışma hayatına dair faydalı bilgiler.',
        'whatsapp_message' => 'Merhaba, sanal ofis / coworking hakkında bilgi almak istiyorum.',
    ],

    /*
     * Tek lokasyon modu (faz 53): yayında tek şube varsa vitrin metinleri o şehre göre okunur. {city} = şehir adı,
     * {city_da} = bulunma hâli ("Konya'da"). Şehir yalnız lokasyon kaydından gelir; panelde ezilen metin yine kazanır.
     */
    'texts_single' => [
        'topbar' => '{city_da} tek sözleşmeyle tüm çözümlere erişim',
        'topbar_count' => '',
        'hero_eyebrow' => '{city_da} sanal ofis · hazır ofis · coworking',
        'hero_title' => 'Şirketinizin {city} adresi',
        'hero_lede' => '{city_da} tescile uygun adres, karşılanan çağrılar ve evraklar, saatlik toplantı odaları. Tek sözleşme, tek merkez, tüm çözümler.',
        'hero_match' => '{city_da} ihtiyacınıza uygun çalışma alanını keşfedin.',
        'solutions_title' => '{city_da} ofis çözümleri: çalışma biçiminize göre başlangıç noktası',
        'solutions_lede' => 'Hepsi aynı merkezde ve aynı altyapıda: resepsiyon, fiber, evrak ve kargo karşılama dahildir.',
        'locations_title' => 'İşinizin merkezinde, profesyonel çalışma alanınız.',
        'locations_blurb' => '{city}, köklü ticaret kültürü, büyüyen sanayisi ve genç girişimci ekosistemiyle iş dünyasında güçlü bir merkez. Şirketinizin adresi bu ekosistemin kalbinde; müşterileriniz ve iş ortaklarınız için prestijli, ulaşılabilir bir konumda olsun.',
        'meeting_title' => '{city_da} saatlik toplantı odası, aynı gün teyitli',
        'nav_locations' => 'Lokasyon',
        'lead_title' => '{city_da} ofisiniz için formu bırakın, aynı iş günü fiyat gelsin',
    ],

    'themes' => [
        'kum' => 'Kum — sıcak zemin, orman yeşili (varsayılan)',
        'gece' => 'Gece — koyu zemin, açık yeşil vurgu',
        'deniz' => 'Deniz — açık zemin, lacivert vurgu',
    ],

    // Kurulum kimliği (faz 60f): önbellek anahtarı bağlamı — aynı Redis'i paylaşan iki kurulum birbirinin anahtarını okuyamaz.
    'installation_id' => env('OFISVIO_INSTALLATION_ID', 'default'),

    // Performance Command Center (faz 60f): istek profili — örnekleme oranı ve yavaş sorgu eşiği (teknik sabit).
    'performance' => [
        'enabled' => (bool) env('PERF_PROFILER', true),
        'sample_rate' => (float) env('PERF_SAMPLE_RATE', 0.05),
        'slow_query_ms' => (int) env('PERF_SLOW_QUERY_MS', 100),
    ],

    'kyc' => [
        'scanner' => env('KYC_SCANNER', 'none'),
        'clamav' => [
            'address' => env('CLAMAV_ADDRESS', 'tcp://127.0.0.1:3310'),
            'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
        ],
    ],

    /*
     * Ürün adı — teknik sabit (başlıklar, panel, "Altyapı: Ofisvio").
     * İletişim/kimlik (telefon, e-posta, slogan, yasal unvan) ve tüm ticari
     * içerik (çözümler, fiyatlar, odalar, planlar, footer) VERİTABANINDADIR:
     * websites tablosu + site_blocks (SiteBlockSeeder / panel "Ana sayfa").
     * Audit kuralı: config'te fiyat ya da müşteri-görünür ticari veri olmaz.
     */
    /*
     * Güvenlik başlıkları (SecurityHeaders middleware). Dış kaynak listesi burada;
     * yeni bir CDN/gömme eklenirse önce buraya yazılır. HSTS yalnız HTTPS yanıtlarında.
     */
    // Yedekleme (audit F-03): yol, retention, şifreleme anahtarı (base64:32 bayt ya da parola).
    'backup' => [
        'path' => env('BACKUP_PATH', storage_path('app/backups')),
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),
        'keep_min' => (int) env('BACKUP_KEEP_MIN', 3),
        'encryption_key' => env('BACKUP_ENCRYPTION_KEY', ''),
        'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26), // doctor: doğrulanmış son yedek bundan eskiyse hata (üretim)
    ],

    'security' => [
        'hsts' => (bool) env('SECURITY_HSTS', true),
        // Ters proxy (audit F-12): boş = güven yok; '*' = tek proxy katmanı; 'ip1,ip2' = liste.
        'trusted_proxies' => (string) env('TRUSTED_PROXIES', ''),
        'style_src' => ['https://fonts.googleapis.com'],
        'font_src' => ['https://fonts.gstatic.com'],
        // İçerik gömmeleri (faz 48 kısa kodları): YouTube (nocookie), Vimeo, Google Haritalar.
        'frame_src' => ['https://www.openstreetmap.org', 'https://www.youtube-nocookie.com', 'https://www.youtube.com', 'https://player.vimeo.com', 'https://www.google.com'],
    ],

    'brand' => [
        'name' => 'Ofisvio',
    ],

    /*
     * Hesap açılışı (ofisvio:bootstrap-accounts). Değerler yalnız .env'den; config:cache
     * ile uyumlu olsun diye env() burada okunur, komutta config() kullanılır.
     */
    /** Bildirim merkezi ilk alıcısı: yalnız env, yalnız bootstrap komutu okur (kodda telefon yok). */
    'notifications' => ['booking_whatsapp' => env('OFISVIO_BOOKING_NOTIFY_WHATSAPP')],

    'accounts' => [
        'admin_email' => env('OFISVIO_ADMIN_EMAIL'),
        'admin_password' => env('OFISVIO_ADMIN_PASSWORD'),
        'test_customer_email' => env('OFISVIO_TEST_CUSTOMER_EMAIL'),
        'test_customer_password' => env('OFISVIO_TEST_CUSTOMER_PASSWORD'),
    ],
    'team_sizes' => ['1' => '1 kişi', '2-5' => '2–5 kişi', '6-15' => '6–15 kişi', '16+' => '16+ kişi'],
];
