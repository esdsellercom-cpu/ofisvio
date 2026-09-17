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
        'hero_eyebrow' => 'Sanal ofis · Hazır ofis · Coworking',
        'hero_title' => 'Şirketinizin adresi',
        'hero_accent' => 'bugün',
        'hero_title_after' => 'hazır olsun.',
        'hero_lede' => 'Tescile uygun adres, karşılanan çağrılar ve evraklar, dakikası hesap edilmiş toplantı odaları. Tek sözleşme, tüm lokasyonlara erişim.',
        'solutions_lede' => 'Hepsi aynı altyapıyı paylaşır: resepsiyon, fiber, evrak ve kargo karşılama, şubeler arası geçiş hakkı dahildir.',
        'solutions_title' => 'Çalışma biçiminize göre dört başlangıç noktası',
        'journey_title' => 'Adresiniz altı adımda tescile hazır',
        'journey_lede' => 'Her adımın durumunu panelinizden canlı görürsünüz. Belgeleriniz incelenirken nerede olduğunuzu tahmin etmeniz gerekmez.',
        'locations_title' => 'İşin merkezinde, metroya yürüme mesafesinde',
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
        'blog_title' => 'Çalışma kültürü günlüğü',
        'whatsapp_message' => 'Merhaba, sanal ofis / coworking hakkında bilgi almak istiyorum.',
    ],

    'themes' => [
        'kum' => 'Kum — sıcak zemin, orman yeşili (varsayılan)',
        'gece' => 'Gece — koyu zemin, açık yeşil vurgu',
        'deniz' => 'Deniz — açık zemin, lacivert vurgu',
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
