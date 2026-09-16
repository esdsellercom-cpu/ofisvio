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
        'solutions_title' => 'Çalışma biçiminize göre dört başlangıç noktası',
        'journey_title' => 'Adresiniz altı adımda tescile hazır',
        'journey_lede' => 'Her adımın durumunu panelinizden canlı görürsünüz. Belgeleriniz incelenirken nerede olduğunuzu tahmin etmeniz gerekmez.',
        'locations_title' => 'İşin merkezinde, metroya yürüme mesafesinde',
        'meeting_title' => 'Saatlik toplantı odası, aynı gün teyitli',
        'meeting_lede' => '4 kişilik görüşme odasından 120 kişilik etkinlik katına kadar. Ekran, beyaz tahta, ikram ve teknik ekip fiyata dahil; üyelere aylık kredi tanımlanır.',
        'amenities_title' => 'Her katta olması gerekenler, ek fatura olmadan',
        'pricing_title' => 'Şeffaf karşılaştırma',
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

    'brand' => [
        'name' => 'Ofisvio',
        'legal_name' => 'Ofisvio Gayrimenkul ve İşletme A.Ş.',
        'phone' => '0850 840 00 00',
        'phone_href' => 'tel:+908508400000',
        'email' => 'merhaba@ofisvio.com',
        'tagline' => 'Sanal ofis ve esnek çalışma alanı operatörü. Levent, İstanbul merkezli.',
    ],

    /*
     * PROJEYE UYARLAMA: Tasarımda liste "Hazır Ofis" ile başlıyordu. Ofisvio'nun
     * backend'i şirket tescil sürecini modelliyor (KYC → sözleşme → ödeme →
     * ADRES TAHSİSİ → aktif), yani amiral ürün sanal ofistir. Sıralama buna
     * göre değiştirildi; dört çözümün hepsi korundu.
     */
    'solutions' => [
        [
            'key' => 'sanal-ofis',
            'title' => 'Sanal Ofis',
            'desc' => 'Prestijli tescil adresi, gelen çağrı ve kargo karşılama, aylık evrak bildirimi.',
            'price' => '₺790/ay\'dan',
            'flagship' => true,
        ],
        [
            'key' => 'hazir-ofis',
            'title' => 'Hazır Ofis',
            'desc' => '2–40 kişilik kilitli ofisler; mobilya, dolap ve marka tabelası kurulu şekilde teslim.',
            'price' => '₺13.900/ay\'dan',
            'flagship' => false,
        ],
        [
            'key' => 'coworking',
            'title' => 'Coworking',
            'desc' => 'Sabit ya da serbest masa; tüm şubelerde geçerli üyelik ve aylık toplantı kredisi.',
            'price' => '₺2.500/ay\'dan',
            'flagship' => false,
        ],
        [
            'key' => 'gun-gecisi',
            'title' => 'Gün Geçişi',
            'desc' => 'Sözleşmesiz günlük kullanım. Hızlı internet, telefon kabini, gün boyu ikram.',
            'price' => '₺390/gün\'den',
            'flagship' => false,
        ],
    ],

    'room_types' => [
        ['title' => 'Görüşme Odası', 'meta' => '2–4 kişi · ekran, beyaz tahta', 'price' => '₺420/saat'],
        ['title' => 'Toplantı Odası', 'meta' => '6–14 kişi · video konferans', 'price' => '₺780/saat'],
        ['title' => 'Etkinlik Katı', 'meta' => '40–120 kişi · sahne, ses, ikram', 'price' => '₺9.500/gün'],
    ],

    'amenities' => [
        ['title' => 'Fiber ve yedek hat', 'desc' => '1 Gbps simetrik, kesintide otomatik ikinci operatöre geçiş.'],
        ['title' => 'Resepsiyon', 'desc' => '08.30–19.00 karşılama, kargo teslim ve misafir yönlendirme.'],
        ['title' => 'Evrak ve kargo bildirimi', 'desc' => 'Adınıza gelen her evrak aynı gün panelinize düşer.'],
        ['title' => 'Telefon kabinleri', 'desc' => 'Her katta akustik yalıtımlı iki kişilik görüşme kabini.'],
        ['title' => 'Kilitli depo', 'desc' => 'Ofis dışı arşiv ve numune için dolap ve palet alanı.'],
        ['title' => '7/24 erişim', 'desc' => 'Kartlı geçiş, gece ve hafta sonu sınırsız kullanım.'],
        ['title' => 'Baskı merkezi', 'desc' => 'Renkli A3 baskı, tarama, ciltleme; aylık kota dahil.'],
        ['title' => 'Barista kahvesi', 'desc' => 'Gün boyu espresso, filtre kahve, çay ve meyve.'],
    ],

    'plans' => [
        ['name' => 'Sanal Ofis', 'price' => '₺790/ay'],
        ['name' => 'Coworking', 'price' => '₺2.500/ay'],
        ['name' => 'Hazır Ofis', 'price' => '₺13.900/ay'],
        ['name' => 'Gün Geçişi', 'price' => '₺390/gün'],
    ],

    'plan_rows' => [
        ['label' => 'Şirket tescil adresi', 'cells' => ['Dahil', 'Opsiyonel', 'Dahil', '—']],
        ['label' => 'Çağrı ve kargo karşılama', 'cells' => ['Çağrı + kargo', 'Kargo', 'Çağrı + kargo', '—']],
        ['label' => 'Çalışma alanı', 'cells' => ['—', 'Sabit masa', 'Kilitli özel ofis', 'Serbest masa']],
        ['label' => 'Tüm şubelere erişim', 'cells' => ['—', 'Tüm şubeler', 'Tüm şubeler', 'Tek şube']],
        ['label' => 'Toplantı odası kredisi', 'cells' => ['2 saat/ay', '4 saat/ay', '16 saat/ay', '—']],
        ['label' => '7/24 kartlı geçiş', 'cells' => ['—', 'Dahil', 'Dahil', '—']],
        ['label' => 'Minimum süre', 'cells' => ['12 ay', '1 ay', '3 ay', 'Yok']],
    ],

    'pricing_note' => 'Fiyatlar KDV hariç, aylıktır. Yıllık sözleşmede iki ay ücretsizdir; depozito bir aylık bedeldir.',

    'footer_columns' => [
        ['title' => 'Çözümler', 'items' => ['Sanal Ofis', 'Hazır Ofis', 'Coworking', 'Gün Geçişi', 'Toplantı Odası', 'Etkinlik Alanı']],
        ['title' => 'Kurumsal', 'items' => ['Hakkımızda', 'Franchise', 'Kariyer', 'Basın', 'S.S.S.', 'İletişim']],
    ],

    'solution_options' => ['Sanal Ofis', 'Hazır Ofis', 'Coworking', 'Gün Geçişi', 'Toplantı Odası', 'Etkinlik Alanı'],

    'team_sizes' => ['1' => '1 kişi', '2-5' => '2–5 kişi', '6-15' => '6–15 kişi', '16+' => '16+ kişi'],
];
