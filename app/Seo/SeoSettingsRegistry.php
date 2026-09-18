<?php

namespace App\Seo;

use InvalidArgumentException;

/**
 * SEO & GEO gelişmiş ayar tanımları (faz 44). Değerler `websites.seo_settings` (JSON, site başına);
 * tanım (sekme, tip, varsayılan, doğrulama, açıklama) KODDA. Yalın sistem (websites.seo_* sütunları:
 * başlık son eki, varsayılan açıklama, dil, indeksleme bayrağı) olduğu gibi kalır; buradakiler onun
 * üstüne biner ve boş bırakılınca hiçbir şeyi değiştirmez.
 *
 * Sekme modu = hangi rota/izinle yazılır:
 *   edit        seo.edit                              — metin/şema/GEO/dil/iç bağlantı/teknik
 *   critical    seo.settings + JIT (seo_settings)     — robots, sitemap, URL/yönlendirme, güvenlik, geliştirici kodu
 *   integration seo.integrations + JIT (seo_settings) — doğrulama meta'ları, analitik kimlikleri, IndexNow
 *   entity      geo.settings + JIT (geo_entity)       — Knowledge Graph varlığı
 *
 * Tipler: bool · int · string · url · date · text · select · multi (seçenek listesinden çoklu)
 *         · lines (satır başına bir değer) · rows (sütunlu satırlar) · json (JSON-LD nesnesi/dizisi)
 * Varsayılanlar TEKNİK sabittir (bot adları, sınırlar); ticari/kimlik verisi varsayılan taşımaz.
 */
final class SeoSettingsRegistry
{
    public const MODES = ['edit', 'critical', 'integration', 'entity'];

    /** @var array<string, array{label: string, mode: string, lead: string}> */
    public const TABS = [
        'tarama' => ['label' => 'Tarama & indeksleme', 'mode' => 'critical', 'lead' => 'robots.txt, sitemap.xml ve <meta name="robots"> yönergeleri. Ana indeksleme bayrağı (yalın ayar) kapalıysa buradaki hiçbir seçenek siteyi indekse sokmaz.'],
        'url' => ['label' => 'Canonical & URL', 'mode' => 'critical', 'lead' => 'Canonical adres, https/www/eğik çizgi standardı ve eski adres yönlendirmeleri. Yönlendirmeler yalnız vitrin adreslerine uygulanır (panel/giriş etkilenmez).'],
        'meta' => ['label' => 'Meta', 'mode' => 'edit', 'lead' => 'Başlık şablonu, Open Graph ve Twitter/X kartı varsayılanları. Sayfanın kendi meta alanı doluysa o kazanır.'],
        'sema' => ['label' => 'Schema.org', 'mode' => 'edit', 'lead' => 'JSON-LD üretimi tür bazında açılıp kapanır; yalnız veri kaynağı olan türler listelenir (uydurma şema üretilmez). Özel JSON-LD site geneline ya da yola bağlı eklenir.'],
        'geo' => ['label' => 'GEO / AI arama', 'mode' => 'edit', 'lead' => 'Üretken arama motorları (ChatGPT, Perplexity, Gemini, Claude) için yapılandırılmış işletme tanımı: llms.txt, Organization şeması ve makine tarafından okunur özet buradan beslenir. llms.txt yardımcı parçadır; asıl değer şema + varlık bilgisi + erişilebilir içerik + doğru iç bağlantılardan gelir.'],
        'varlik' => ['label' => 'Entity / Knowledge Graph', 'mode' => 'entity', 'lead' => 'Organization düğümünün kimliği: alternatif adlar, logo, kuruluş, tür, kurucu, Wikidata/Wikipedia. Telefon, e-posta, adres ve sosyal profiller (sameAs) site marka ayarlarından gelir.'],
        'yerel' => ['label' => 'Yerel SEO', 'mode' => 'edit', 'lead' => 'Şube başına adres, telefon, koordinat, çalışma saati ve posta kodu Lokasyonlar ekranında tutulur; burada site geneli LocalBusiness türü, harita/işletme profili bağlantıları ve hizmet verilen şehir/ilçeler tanımlanır.'],
        'dil' => ['label' => 'Dil & ülke', 'mode' => 'edit', 'lead' => 'Varsayılan dil yalın ayardaki og:locale\'dir. Hreflang açılınca her sayfa için dil alternatifleri ve x-default basılır; alternatif adres bugünkü yolu korur (örn. https://en.site.com + /blog/yazi).'],
        'baglanti' => ['label' => 'İç bağlantı', 'mode' => 'edit', 'lead' => 'Anahtar kelime → adres eşlemesi içerik gövdesinde ilk geçtiği yerde bağlantıya çevrilir (başlık, kod ve mevcut bağlantıların içi hariç). Yetim ve kırık bağlantılar Teknik sekmesinde raporlanır.'],
        'yonlendirme' => ['label' => 'Yönlendirme & 404', 'mode' => 'edit', 'lead' => 'Akıllı URL yönetimi: silinen/taşınan adresler URL geçmişi → yönlendirme tablosu → benzerlik analizi → üst kategori → ana sayfa sırasıyla çözülür. Eşik altı eşleşme asla sessizce yönlendirilmez; öneri olarak Yönlendirmeler & 404 merkezinde onay bekler.'],
        'teknik' => ['label' => 'Teknik', 'mode' => 'edit', 'lead' => 'Deterministik teknik denetim (dış servis yok): çift/eksik başlık ve açıklama, kırık iç bağlantı, yetim sayfa, yönlendirme zinciri, alt metinsiz görsel, karışık içerik. HTML site haritası ve tembel görsel yükleme buradan açılır.'],
        'dogrulama' => ['label' => 'Doğrulama & bildirim', 'mode' => 'integration', 'lead' => 'Arama motoru doğrulama meta etiketleri, GA4/GTM kimlikleri ve IndexNow. Google Indexing API servis hesabı ister; kimlik bilgisi altyapısı gelince eklenir.'],
        'guvenlik' => ['label' => 'Güvenlik', 'mode' => 'critical', 'lead' => 'Yanıt başlıkları (HSTS, CSP, Referrer-Policy) uygulama genelinde SecurityHeaders ile verilir; burada X-Robots-Tag ve bot erişim özeti yönetilir. Karışık içerik denetimi Teknik sekmesindedir.'],
        'gelistirici' => ['label' => 'Geliştirici', 'mode' => 'critical', 'lead' => 'Ham kod alanları olduğu gibi basılır — yalnız güvenilen personel (JIT). Özel robots.txt Tarama, özel JSON-LD Schema.org sekmesindedir.'],
    ];

    /** Sitemap'e girebilen sayfa türleri. */
    public const SITEMAP_TYPES = ['pages' => 'Sayfalar', 'posts' => 'Yazılar', 'categories' => 'Kategoriler', 'tags' => 'Etiketler', 'locations' => 'Lokasyonlar', 'services' => 'Hizmetler', 'events' => 'Etkinlikler', 'landing' => 'Hizmet × şehir sayfaları'];

    /** JSON-LD türleri: yalnız veri kaynağı olanlar (Product/HowTo/Review için kaynak yok — üretilmez). */
    public const SCHEMA_TYPES = ['Organization' => 'Organization', 'WebSite' => 'WebSite', 'WebPage' => 'WebPage', 'Article' => 'Article (yazılar)', 'BreadcrumbList' => 'BreadcrumbList', 'FAQPage' => 'FAQPage (## Soru? başlıkları + GEO SSS)', 'Service' => 'Service (hizmetler)', 'LocalBusiness' => 'LocalBusiness (lokasyonlar)', 'Event' => 'Event (etkinlikler)'];

    /** Yaygın AI tarayıcıları (teknik sabit; liste panelden değiştirilir). */
    public const AI_BOTS = ['GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-Web', 'anthropic-ai', 'PerplexityBot', 'Google-Extended', 'CCBot', 'Bytespider', 'Amazonbot', 'Applebot-Extended', 'cohere-ai', 'meta-externalagent', 'Diffbot'];

    /**
     * @return array<string, array{tab: string, label: string, type: string, default: mixed, rules: array<int, string>, description: string, options?: array<int|string, string>, columns?: array<string, array{label: string, type: string, rules: array<int, string>, options?: array<int|string, string>}>, placeholder?: string}>
     */
    public static function definitions(): array
    {
        return [
            // --- Tarama & indeksleme ---
            'crawl.sitemap_enabled' => ['tab' => 'tarama', 'label' => 'sitemap.xml üret', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => 'Kapalıysa /sitemap.xml 404 döner ve robots.txt Sitemap satırı taşımaz.'],
            'crawl.sitemap_types' => ['tab' => 'tarama', 'label' => 'Sitemap\'e dahil sayfa türleri', 'type' => 'multi', 'default' => ['pages', 'posts', 'categories', 'tags', 'locations', 'services', 'events', 'landing'], 'rules' => ['array'], 'options' => self::SITEMAP_TYPES, 'description' => 'Ana sayfa her zaman girer; işaretsiz tür listeden düşer.'],
            'crawl.sitemap_exclude' => ['tab' => 'tarama', 'label' => 'Sitemap\'ten çıkarılacak yollar', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:2000'], 'description' => 'Satır başına bir yol: /eski-sayfa ya da /blog/kategori/* (sonek yıldızı ön ek eşler).', 'placeholder' => "/gizli-kampanya\n/blog/etiket/*"],
            'crawl.sitemap_custom' => ['tab' => 'tarama', 'label' => 'Özel sitemap.xml', 'type' => 'text', 'default' => '', 'rules' => ['string', 'max:200000'], 'description' => 'Doluysa otomatik sitemap yerine bu XML olduğu gibi servis edilir (<?xml ile başlamalı).'],
            'crawl.robots_mode' => ['tab' => 'tarama', 'label' => 'robots.txt kaynağı', 'type' => 'select', 'default' => 'auto', 'rules' => ['in:auto,custom'], 'options' => ['auto' => 'Otomatik (bayrak + ek satırlar + AI kuralları)', 'custom' => 'Özel metin (aşağıdaki alan olduğu gibi)'], 'description' => 'Özel metin seçilince ana indeksleme bayrağı kapalıysa yine "Disallow: /" basılır.'],
            'crawl.robots_extra' => ['tab' => 'tarama', 'label' => 'robots.txt ek satırları', 'type' => 'text', 'default' => '', 'rules' => ['string', 'max:5000'], 'description' => 'Otomatik modda "User-agent: *" bloğunun sonuna eklenir (örn. Disallow: /kampanya/, Crawl-delay: 5).'],
            'crawl.robots_custom' => ['tab' => 'tarama', 'label' => 'Özel robots.txt', 'type' => 'text', 'default' => '', 'rules' => ['string', 'max:20000'], 'description' => 'Yalnız kaynak "Özel metin" iken kullanılır.'],
            'crawl.index_query_urls' => ['tab' => 'tarama', 'label' => 'Parametreli URL\'ler indekslensin', 'type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'description' => 'Kapalıyken ?sayfa=2, ?utm_… gibi sorgu dizeli istekler noindex alır; canonical parametresiz adrestir.'],
            'crawl.noindex_listings' => ['tab' => 'tarama', 'label' => 'Liste sayfaları noindex', 'type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'description' => 'Etiket ve kategori sayfaları noindex (ince içerik önlemi); sitemap\'ten de düşer.'],
            'crawl.nofollow_default' => ['tab' => 'tarama', 'label' => 'nofollow varsayılanı', 'type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'description' => 'Her sayfada "nofollow" — genelde kapalı kalmalı.'],
            'crawl.noarchive' => ['tab' => 'tarama', 'label' => 'noarchive', 'type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'description' => 'Önbelleğe alınmış kopya gösterilmesin.'],
            'crawl.nosnippet' => ['tab' => 'tarama', 'label' => 'nosnippet', 'type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'description' => 'Arama sonucunda metin/görsel parçası gösterilmesin (AI özetlerini de keser).'],
            'crawl.max_snippet' => ['tab' => 'tarama', 'label' => 'max-snippet', 'type' => 'int', 'default' => -1, 'rules' => ['integer', 'min:-1', 'max:1000'], 'description' => '-1 = sınırsız (yönerge basılmaz), 0 = parça yok.'],
            'crawl.max_image_preview' => ['tab' => 'tarama', 'label' => 'max-image-preview', 'type' => 'select', 'default' => 'large', 'rules' => ['in:none,standard,large'], 'options' => ['none' => 'none', 'standard' => 'standard', 'large' => 'large'], 'description' => 'Görsel önizleme boyutu.'],
            'crawl.max_video_preview' => ['tab' => 'tarama', 'label' => 'max-video-preview (sn)', 'type' => 'int', 'default' => -1, 'rules' => ['integer', 'min:-1', 'max:3600'], 'description' => '-1 = sınırsız (yönerge basılmaz).'],
            'crawl.ai_crawlers_allowed' => ['tab' => 'tarama', 'label' => 'AI tarayıcılarına izin ver', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => 'Kapatınca aşağıdaki botlara robots.txt\'de "Disallow: /" yazılır ve llms.txt servis edilmez. AI aramada görünmek istiyorsanız açık kalmalı.'],
            'crawl.ai_bots' => ['tab' => 'tarama', 'label' => 'AI bot listesi', 'type' => 'lines', 'default' => self::AI_BOTS, 'rules' => ['string', 'max:3000'], 'description' => 'Satır başına bir User-agent. Erişim kapalıyken ya da kapalı yollar tanımlıyken kural bu botlara yazılır.'],
            'crawl.ai_disallow_paths' => ['tab' => 'tarama', 'label' => 'AI erişimine kapalı yollar', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:3000'], 'description' => 'Satır başına bir yol; AI botlarına Disallow olarak yazılır, llms.txt bu sayfaları listelemez.', 'placeholder' => "/blog/etiket/\n/kampanya"],

            // --- Canonical & URL ---
            'url.canonical_auto' => ['tab' => 'url', 'label' => 'Otomatik canonical', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => 'Her sayfada <link rel="canonical"> basılır (önerilir).'],
            'url.canonical_host' => ['tab' => 'url', 'label' => 'Canonical alan adı', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:190', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/'], 'description' => 'Boşsa sitenin alan adı. Canonical ve sitemap adresleri bu alan adıyla üretilir; www tercihiyle tutarlı olmalı.', 'placeholder' => 'www.ornek.com'],
            'url.force_https' => ['tab' => 'url', 'label' => 'HTTP → HTTPS', 'type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'description' => 'Alan adı tanımlı sitede http istekleri 301 ile https\'e döner. Sunucu/CDN katmanı zaten yönlendiriyorsa kapalı bırakın (varsayılan).'],
            'url.www' => ['tab' => 'url', 'label' => 'www tercihi', 'type' => 'select', 'default' => 'none', 'rules' => ['in:none,www,non_www'], 'options' => ['none' => 'Dokunma', 'www' => 'www\'lu adrese yönlendir', 'non_www' => 'www\'suz adrese yönlendir'], 'description' => 'Alan adı tanımlı sitede 301.'],
            'url.trailing_slash' => ['tab' => 'url', 'label' => 'Sondaki eğik çizgi', 'type' => 'select', 'default' => 'strip', 'rules' => ['in:strip,keep'], 'options' => ['strip' => 'Kaldır (/sayfa/ → /sayfa)', 'keep' => 'Dokunma'], 'description' => 'Kök (/) etkilenmez.'],
            'url.lowercase' => ['tab' => 'url', 'label' => 'Küçük harf standardı', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => 'Büyük harf içeren yol küçük harfe 301 ile yönlendirilir (yalnız vitrin yolları).'],
            'url.strip_params' => ['tab' => 'url', 'label' => 'Canonical\'dan temizlenecek parametreler', 'type' => 'lines', 'default' => ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'msclkid'], 'rules' => ['string', 'max:2000'], 'description' => 'Bu parametreler canonical adresten düşer (yönlendirme yapılmaz; analitik bozulmaz).'],
            'url.redirects' => ['tab' => 'url', 'label' => 'Yönlendirmeler', 'type' => 'rows', 'default' => [], 'rules' => ['array', 'max:500'], 'columns' => [
                'from' => ['label' => 'Eski yol', 'type' => 'string', 'rules' => ['required', 'string', 'max:300', 'regex:#^/[^\s]*$#']],
                'to' => ['label' => 'Yeni adres', 'type' => 'string', 'rules' => ['required', 'string', 'max:500', 'regex:#^(/|https?://)[^\s]*$#']],
                'code' => ['label' => 'Kod', 'type' => 'select', 'rules' => ['in:301,302'], 'options' => ['301' => '301 kalıcı', '302' => '302 geçici']],
            ], 'description' => 'Tam yol eşleşir; "/eski/*" ön ek eşler ve kalan kısım hedefe eklenir. Vitrin dışı yollar (panel, giriş) yönlendirilmez.'],

            // --- Yönlendirme & 404 (faz 54) ---
            'redirect.auto_threshold' => ['tab' => 'yonlendirme', 'label' => 'Otomatik yönlendirme eşiği (%)', 'type' => 'int', 'default' => 85, 'rules' => ['integer', 'min:50', 'max:100'], 'description' => 'Benzerlik skoru bu değerin üstündeyse eski adres otomatik 301 ile en alakalı içeriğe yönlendirilir.'],
            'redirect.review_threshold' => ['tab' => 'yonlendirme', 'label' => 'Onay eşiği (%)', 'type' => 'int', 'default' => 60, 'rules' => ['integer', 'min:0', 'max:100'], 'description' => 'Bu değer ile otomatik eşik arasındaki eşleşme öneri olur, admin onayı bekler. Altındaki eşleşme yalnız 404 sayfasında "belki aradığınız" bağlantısı olarak gösterilir.'],
            'redirect.default_code' => ['tab' => 'yonlendirme', 'label' => 'Varsayılan kod', 'type' => 'select', 'default' => '301', 'rules' => ['in:301,302,307,308'], 'options' => ['301' => '301 kalıcı (önerilir)', '302' => '302 geçici', '307' => '307 geçici', '308' => '308 kalıcı'], 'description' => 'Kalıcı URL değişikliklerinde 301 kullanılır; geçici kampanya adresleri için 302/307.'],
            'redirect.fallback' => ['tab' => 'yonlendirme', 'label' => 'Bilinen eski adres için son çare', 'type' => 'select', 'default' => 'section', 'rules' => ['in:section,home,none'], 'options' => ['section' => 'İlgili kategori / hizmetler / lokasyonlar listesi, yoksa ana sayfa', 'home' => 'Ana sayfa', 'none' => 'Yönlendirme yapma (404 kalsın)'], 'description' => 'Yalnız URL geçmişinde olan (gerçekten var olmuş) adresler için; rastgele/yanlış adresler hiçbir zaman ana sayfaya yönlendirilmez.'],
            'redirect.log_404' => ['tab' => 'yonlendirme', 'label' => '404 günlüğü tut', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => 'Vitrindeki 404 istekleri yol bazında sayılır (ilk/son görülme, referer, öneri). Dosya uzantılı bot taramaları (.php, .env …) günlüğe girmez.'],

            // --- Meta ---
            'meta.title_template' => ['tab' => 'meta', 'label' => 'Başlık şablonu', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:120'], 'description' => 'Boşsa "{title} {sonek}" (yalın ayar). Yer tutucular: {title} {site} {suffix}.', 'placeholder' => '{title} | {site}'],
            'meta.description_template' => ['tab' => 'meta', 'label' => 'Açıklama şablonu', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:200'], 'description' => 'Boşsa sayfa açıklaması olduğu gibi. Yer tutucular: {description} {site}.', 'placeholder' => '{description} — {site}'],
            'meta.keywords' => ['tab' => 'meta', 'label' => 'Meta keywords', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:300'], 'description' => 'Arama motorları dikkate almaz; yalnız istenirse (virgülle). Boşsa etiket basılmaz.'],
            'meta.og_enabled' => ['tab' => 'meta', 'label' => 'Open Graph', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => 'og:* etiketleri (paylaşım kartları).'],
            'meta.og_title' => ['tab' => 'meta', 'label' => 'Varsayılan OG başlığı', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:120'], 'description' => 'Yalnız ana sayfa/liste gibi içeriksiz sayfalarda; içerik sayfası kendi başlığını kullanır.'],
            'meta.og_description' => ['tab' => 'meta', 'label' => 'Varsayılan OG açıklaması', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:300'], 'description' => 'Boşsa meta açıklama.'],
            'meta.og_image' => ['tab' => 'meta', 'label' => 'Varsayılan OG görseli', 'type' => 'url', 'default' => '', 'rules' => ['url:https', 'max:500'], 'description' => 'Sayfanın kapak görseli yoksa (1200×630 önerilir).'],
            'meta.twitter_card' => ['tab' => 'meta', 'label' => 'Twitter/X kartı', 'type' => 'select', 'default' => 'summary', 'rules' => ['in:none,summary,summary_large_image'], 'options' => ['none' => 'Basma', 'summary' => 'summary', 'summary_large_image' => 'summary_large_image'], 'description' => ''],
            'meta.twitter_site' => ['tab' => 'meta', 'label' => 'Twitter/X hesabı', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:40', 'regex:/^@?[A-Za-z0-9_]{1,30}$/'], 'description' => 'twitter:site (örn. @ofisvio).'],
            'meta.twitter_title' => ['tab' => 'meta', 'label' => 'Varsayılan Twitter başlığı', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:120'], 'description' => 'Boşsa OG/sayfa başlığı.'],
            'meta.twitter_description' => ['tab' => 'meta', 'label' => 'Varsayılan Twitter açıklaması', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:300'], 'description' => 'Boşsa OG/meta açıklama.'],
            'meta.twitter_image' => ['tab' => 'meta', 'label' => 'Varsayılan Twitter görseli', 'type' => 'url', 'default' => '', 'rules' => ['url:https', 'max:500'], 'description' => 'Boşsa OG görseli.'],

            // --- Schema.org ---
            'schema.enabled' => ['tab' => 'sema', 'label' => 'JSON-LD üret', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => 'Kapalıysa hiçbir sayfada yapılandırılmış veri basılmaz (özel JSON-LD dahil).'],
            'schema.types' => ['tab' => 'sema', 'label' => 'Etkin türler', 'type' => 'multi', 'default' => array_keys(self::SCHEMA_TYPES), 'rules' => ['array'], 'options' => self::SCHEMA_TYPES, 'description' => 'İşaretsiz tür @graph\'tan düşer.'],
            'schema.custom_sitewide' => ['tab' => 'sema', 'label' => 'Site geneli özel JSON-LD', 'type' => 'json', 'default' => '', 'rules' => ['string', 'max:20000'], 'description' => 'Her sayfanın @graph\'ına eklenen bir nesne ya da nesne dizisi (@context olmadan).', 'placeholder' => '{"@type":"Organization","@id":"…"}'],
            'schema.custom_by_path' => ['tab' => 'sema', 'label' => 'Yola bağlı özel JSON-LD', 'type' => 'rows', 'default' => [], 'rules' => ['array', 'max:200'], 'columns' => [
                'path' => ['label' => 'Yol', 'type' => 'string', 'rules' => ['required', 'string', 'max:300', 'regex:#^/[^\s]*$#']],
                'json' => ['label' => 'JSON-LD', 'type' => 'json', 'rules' => ['required', 'string', 'max:20000']],
            ], 'description' => 'Sayfa bazlı şema: yol tam eşleşince nesne @graph\'a eklenir.'],

            // --- GEO / AI arama ---
            'geo.llms_enabled' => ['tab' => 'geo', 'label' => 'llms.txt üret', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => '/llms.txt: markdown biçiminde site özeti (AI tarayıcıları için). AI erişimi kapalıysa servis edilmez.'],
            'geo.llms_auto' => ['tab' => 'geo', 'label' => 'llms.txt otomatik güncellensin', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => 'Açıkken dosya bu sekmedeki tanımlar + yayındaki sayfalar/hizmetler/lokasyonlardan her istekte üretilir (site önbelleği). Kapalıysa aşağıdaki özel metin olduğu gibi servis edilir.'],
            'geo.llms_custom' => ['tab' => 'geo', 'label' => 'Özel llms.txt', 'type' => 'text', 'default' => '', 'rules' => ['string', 'max:50000'], 'description' => 'Yalnız otomatik güncelleme kapalıyken.'],
            'geo.brand_definition' => ['tab' => 'geo', 'label' => 'Marka / işletme tanımı', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:300'], 'description' => 'Tek cümle: kim, ne yapar, kime. Organization şemasının "description" alanı.', 'placeholder' => 'Ofisvio, girişimciler için sanal ofis, hazır ofis ve coworking sunan …'],
            'geo.summary_short' => ['tab' => 'geo', 'label' => 'Kısa işletme özeti', 'type' => 'text', 'default' => '', 'rules' => ['string', 'max:600'], 'description' => 'llms.txt girişi ve makine okunur özet (≤ 600).'],
            'geo.summary_long' => ['tab' => 'geo', 'label' => 'Uzun işletme özeti', 'type' => 'text', 'default' => '', 'rules' => ['string', 'max:5000'], 'description' => 'llms.txt "Hakkında" bölümü (markdown).'],
            'geo.services' => ['tab' => 'geo', 'label' => 'Ana hizmetler', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:3000'], 'description' => 'Satır başına bir hizmet tanımı ("Sanal ofis — tescil adresi ve posta karşılama"). Hizmet modülündeki kayıtlar ayrıca listelenir.'],
            'geo.expertise' => ['tab' => 'geo', 'label' => 'Uzmanlık alanları', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:2000'], 'description' => 'Organization "knowsAbout" + llms.txt.'],
            'geo.locations_served' => ['tab' => 'geo', 'label' => 'Hizmet verilen lokasyonlar', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:2000'], 'description' => 'Şehir/bölge adları; Organization "areaServed".'],
            'geo.audience' => ['tab' => 'geo', 'label' => 'Hedef kitle', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:300'], 'description' => 'Organization "audience" (örn. girişimciler, KOBİ\'ler, serbest çalışanlar).'],
            'geo.faq' => ['tab' => 'geo', 'label' => 'Sık sorulan sorular', 'type' => 'rows', 'default' => [], 'rules' => ['array', 'max:50'], 'columns' => [
                'q' => ['label' => 'Soru', 'type' => 'string', 'rules' => ['required', 'string', 'max:200']],
                'a' => ['label' => 'Cevap', 'type' => 'text', 'rules' => ['required', 'string', 'max:2000']],
            ], 'description' => 'Ana sayfada FAQPage şeması (≥ 2 çift) ve llms.txt "SSS" bölümü.'],
            'geo.priority_urls' => ['tab' => 'geo', 'label' => 'AI için öncelikli sayfalar', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:3000'], 'description' => 'Satır başına bir yol ya da adres; llms.txt "Önemli sayfalar" bölümünde en üstte.'],
            'geo.resources' => ['tab' => 'geo', 'label' => 'Önemli kaynaklar', 'type' => 'rows', 'default' => [], 'rules' => ['array', 'max:50'], 'columns' => [
                'title' => ['label' => 'Başlık', 'type' => 'string', 'rules' => ['required', 'string', 'max:120']],
                'url' => ['label' => 'Adres', 'type' => 'string', 'rules' => ['required', 'string', 'max:500', 'regex:#^(/|https?://)[^\s]*$#']],
            ], 'description' => 'Referans gösterilmesini istediğiniz belgeler (fiyat listesi, KVKK, rehberler).'],

            // --- Entity / Knowledge Graph ---
            'entity.alternate_names' => ['tab' => 'varlik', 'label' => 'Alternatif marka adları', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:1000'], 'description' => 'Organization "alternateName".'],
            'entity.logo' => ['tab' => 'varlik', 'label' => 'Logo adresi', 'type' => 'url', 'default' => '', 'rules' => ['url:https', 'max:500'], 'description' => 'Organization "logo" (kare/yatay PNG-SVG, ≥ 112 px).'],
            'entity.founding_date' => ['tab' => 'varlik', 'label' => 'Kuruluş tarihi', 'type' => 'date', 'default' => '', 'rules' => ['date_format:Y-m-d'], 'description' => 'Organization "foundingDate".'],
            'entity.org_type' => ['tab' => 'varlik', 'label' => 'Şirket türü (schema)', 'type' => 'select', 'default' => 'Organization', 'rules' => ['in:Organization,Corporation,LocalBusiness,ProfessionalService,RealEstateAgent'], 'options' => ['Organization' => 'Organization', 'Corporation' => 'Corporation', 'LocalBusiness' => 'LocalBusiness', 'ProfessionalService' => 'ProfessionalService', 'RealEstateAgent' => 'RealEstateAgent'], 'description' => 'Organization düğümünün @type değeri.'],
            'entity.founder' => ['tab' => 'varlik', 'label' => 'Kurucu', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:120'], 'description' => 'Organization "founder" (Person).'],
            'entity.description' => ['tab' => 'varlik', 'label' => 'Marka/şirket açıklaması', 'type' => 'text', 'default' => '', 'rules' => ['string', 'max:1000'], 'description' => 'Boşsa GEO sekmesindeki marka tanımı kullanılır.'],
            'entity.wikidata_id' => ['tab' => 'varlik', 'label' => 'Wikidata ID', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:20', 'regex:/^Q[0-9]+$/'], 'description' => 'Q ile başlar; sameAs\'e https://www.wikidata.org/wiki/Q… olarak eklenir.'],
            'entity.wikipedia_url' => ['tab' => 'varlik', 'label' => 'Wikipedia adresi', 'type' => 'url', 'default' => '', 'rules' => ['url:https', 'max:500'], 'description' => 'sameAs\'e eklenir.'],
            'entity.knowledge_panel_url' => ['tab' => 'varlik', 'label' => 'Google Knowledge Panel adresi', 'type' => 'url', 'default' => '', 'rules' => ['url:https', 'max:500'], 'description' => 'Bilgi paneli / g.co/kgs bağlantısı; sameAs\'e eklenir.'],
            'entity.areas_served' => ['tab' => 'varlik', 'label' => 'Çalışma bölgeleri', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:2000'], 'description' => 'Organization "areaServed" (GEO sekmesindeki lokasyonlarla birleşir).'],

            // --- Yerel SEO ---
            'local.business_type' => ['tab' => 'yerel', 'label' => 'LocalBusiness türü', 'type' => 'select', 'default' => 'CoworkingSpace', 'rules' => ['in:LocalBusiness,CoworkingSpace,OfficeEquipmentStore,ProfessionalService'], 'options' => ['CoworkingSpace' => 'CoworkingSpace', 'LocalBusiness' => 'LocalBusiness', 'ProfessionalService' => 'ProfessionalService', 'OfficeEquipmentStore' => 'OfficeEquipmentStore'], 'description' => 'Lokasyon sayfalarındaki LocalBusiness düğümünün "additionalType" değeri.'],
            'local.maps_url' => ['tab' => 'yerel', 'label' => 'Google Maps adresi (merkez)', 'type' => 'url', 'default' => '', 'rules' => ['url:https', 'max:500'], 'description' => 'Organization "hasMap".'],
            'local.gbp_url' => ['tab' => 'yerel', 'label' => 'Google Business Profile adresi', 'type' => 'url', 'default' => '', 'rules' => ['url:https', 'max:500'], 'description' => 'sameAs\'e eklenir.'],
            'local.service_cities' => ['tab' => 'yerel', 'label' => 'Servis verilen şehirler', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:2000'], 'description' => 'llms.txt ve areaServed (City).'],
            'local.service_districts' => ['tab' => 'yerel', 'label' => 'Servis verilen ilçeler', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:2000'], 'description' => 'llms.txt ve areaServed.'],

            // --- Dil & ülke ---
            'lang.country' => ['tab' => 'dil', 'label' => 'Site ülkesi', 'type' => 'string', 'default' => 'TR', 'rules' => ['string', 'regex:/^[A-Z]{2}$/'], 'description' => 'ISO 3166-1 alpha-2; PostalAddress "addressCountry" ve x-default bölge bilgisi.'],
            'lang.hreflang_enabled' => ['tab' => 'dil', 'label' => 'Hreflang bas', 'type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'description' => 'Açıkken varsayılan dil + aşağıdaki alternatifler + x-default her sayfada <link rel="alternate" hreflang> olarak çıkar.'],
            'lang.alternates' => ['tab' => 'dil', 'label' => 'Dil / bölge alternatifleri', 'type' => 'rows', 'default' => [], 'rules' => ['array', 'max:30'], 'columns' => [
                'hreflang' => ['label' => 'hreflang', 'type' => 'string', 'rules' => ['required', 'string', 'max:12', 'regex:/^[a-z]{2}(-[A-Za-z]{2,4})?$/']],
                'url' => ['label' => 'Kök adres', 'type' => 'string', 'rules' => ['required', 'string', 'max:300', 'regex:#^https://[^\s/]+(/[^\s]*)?$#']],
            ], 'description' => 'Örn. en → https://en.ornek.com, en-GB → https://ornek.co.uk. Geçerli sayfanın yolu kök adrese eklenir.'],
            'lang.x_default' => ['tab' => 'dil', 'label' => 'x-default kök adresi', 'type' => 'url', 'default' => '', 'rules' => ['url:https', 'max:300'], 'description' => 'Boşsa sitenin kendi adresi.'],

            // --- İç bağlantı ---
            'links.auto_enabled' => ['tab' => 'baglanti', 'label' => 'Otomatik iç bağlantı', 'type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'description' => 'Aşağıdaki eşleme içerik gövdesine uygulanır.'],
            'links.keywords' => ['tab' => 'baglanti', 'label' => 'Anahtar kelime → adres', 'type' => 'rows', 'default' => [], 'rules' => ['array', 'max:300'], 'columns' => [
                'keyword' => ['label' => 'Anahtar kelime', 'type' => 'string', 'rules' => ['required', 'string', 'min:3', 'max:80']],
                'url' => ['label' => 'Adres', 'type' => 'string', 'rules' => ['required', 'string', 'max:500', 'regex:#^(/|https?://)[^\s]*$#']],
            ], 'description' => 'Büyük/küçük harf duyarsız, kelime sınırında; sayfa kendi adresine bağlanmaz.'],
            'links.max_per_page' => ['tab' => 'baglanti', 'label' => 'Sayfa başına en fazla otomatik bağlantı', 'type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:0', 'max:50'], 'description' => '0 = bağlantı eklenmez. Her kural sayfada en fazla bir kez uygulanır.'],
            'links.max_per_target' => ['tab' => 'baglanti', 'label' => 'Aynı adrese en fazla', 'type' => 'int', 'default' => 1, 'rules' => ['integer', 'min:1', 'max:10'], 'description' => ''],
            'links.breadcrumb_enabled' => ['tab' => 'baglanti', 'label' => 'Görünür breadcrumb', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => 'İçerik sayfalarının üstünde gezinti izi (şema her zaman BreadcrumbList taşır).'],
            'links.related_enabled' => ['tab' => 'baglanti', 'label' => 'İlgili yazılar', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => 'Yazı altında aynı kategori/en yeni yazılar.'],

            // --- Teknik ---
            'technical.html_sitemap' => ['tab' => 'teknik', 'label' => 'HTML site haritası', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => '/site-haritasi: yayındaki sayfa, yazı, lokasyon ve hizmet bağlantıları (ziyaretçi ve tarayıcı için).'],
            'technical.lazy_images' => ['tab' => 'teknik', 'label' => 'Görselleri tembel yükle', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => 'İçerik gövdesindeki görsellere loading="lazy" ve decoding="async" eklenir.'],

            // --- Doğrulama & bildirim ---
            'verify.google' => ['tab' => 'dogrulama', 'label' => 'Google Search Console', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:200', 'regex:/^[A-Za-z0-9_\-=]*$/'], 'description' => 'google-site-verification içeriği (yalnız kod).'],
            'verify.bing' => ['tab' => 'dogrulama', 'label' => 'Bing Webmaster', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:200', 'regex:/^[A-Za-z0-9_\-=]*$/'], 'description' => 'msvalidate.01 içeriği.'],
            'verify.yandex' => ['tab' => 'dogrulama', 'label' => 'Yandex Webmaster', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:200', 'regex:/^[A-Za-z0-9_\-=]*$/'], 'description' => 'yandex-verification içeriği.'],
            'verify.ga4_id' => ['tab' => 'dogrulama', 'label' => 'Google Analytics 4', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:30', 'regex:/^(G-[A-Z0-9]{4,20})?$/'], 'description' => 'Ölçüm kimliği (G-…); gtag.js head\'e eklenir, CSP buna göre genişler.'],
            'verify.gtm_id' => ['tab' => 'dogrulama', 'label' => 'Google Tag Manager', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:30', 'regex:/^(GTM-[A-Z0-9]{4,12})?$/'], 'description' => 'Konteyner kimliği (GTM-…).'],
            // Search Console / GA4 mülk kimlikleri (faz 60d): senkron komutları bu mülkü sorgular; env yalnız kimlik bilgisi taşır.
            'integrations.gsc_property' => ['tab' => 'dogrulama', 'label' => 'Search Console mülkü', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:200', 'regex:#^(sc-domain:[a-z0-9.-]+|https?://[^\s]+/?)?$#'], 'description' => 'sc-domain:alan.com (alan adı mülkü) ya da https://www.alan.com/ (URL öneki). Servis hesabı e-postası mülke en az "Tam" yetkiyle eklenmiş olmalı.'],
            'integrations.ga4_property' => ['tab' => 'dogrulama', 'label' => 'GA4 mülk kimliği (Data API)', 'type' => 'string', 'default' => '', 'rules' => ['string', 'max:20', 'regex:/^[0-9]{0,15}$/'], 'description' => 'Sayısal mülk kimliği (Yönetici › Mülk ayarları). Servis hesabı mülke "Görüntüleyici" olarak eklenmeli.'],
            'verify.extra_meta' => ['tab' => 'dogrulama', 'label' => 'Diğer doğrulama meta etiketleri', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:3000'], 'description' => 'Satır başına "ad=içerik" (örn. p:domain_verify=…; facebook-domain-verification=…).'],
            'indexing.indexnow_enabled' => ['tab' => 'dogrulama', 'label' => 'IndexNow bildirimi', 'type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'description' => 'Bing/Yandex/Naver: içerik yayınlanınca/silinince adres api.indexnow.org\'a kuyruktan bildirilir (INDEXNOW_ENABLED env ile sağlayıcı açık olmalı).'],
            'indexing.indexnow_key' => ['tab' => 'dogrulama', 'label' => 'IndexNow anahtarı', 'type' => 'string', 'default' => '', 'rules' => ['string', 'regex:/^([a-f0-9]{32})?$/'], 'description' => '32 hex karakter; /{anahtar}.txt otomatik servis edilir. Boşsa kaydedince üretilir.'],
            'indexing.notify_on_publish' => ['tab' => 'dogrulama', 'label' => 'Yayında bildir', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => 'Yeni/güncellenen içerik yayınlanınca.'],
            'indexing.notify_on_delete' => ['tab' => 'dogrulama', 'label' => 'Kaldırınca bildir', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'description' => 'Arşivlenen/silinen içerik adresi (motor 404 görüp düşürür).'],
            'indexing.notify_sitemap' => ['tab' => 'dogrulama', 'label' => 'Sitemap adresini de bildir', 'type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'description' => 'Her bildirimde /sitemap.xml de gönderilir.'],

            // --- Güvenlik ---
            'security.x_robots_tag' => ['tab' => 'guvenlik', 'label' => 'X-Robots-Tag başlığı', 'type' => 'select', 'default' => 'auto', 'rules' => ['in:auto,none,noindex,noindex_nofollow'], 'options' => ['auto' => 'Otomatik (indeksleme kapalıysa noindex)', 'none' => 'Basma', 'noindex' => 'noindex', 'noindex_nofollow' => 'noindex, nofollow'], 'description' => 'HTML dışı yanıtlar (PDF, görsel) için de geçerli yanıt başlığı.'],

            // --- Geliştirici ---
            'dev.head_code' => ['tab' => 'gelistirici', 'label' => '<head> özel kodu', 'type' => 'text', 'default' => '', 'rules' => ['string', 'max:20000'], 'description' => '</head> öncesine olduğu gibi basılır. CSP: satır içi script/style serbest, dış kaynak yalnız izinli alanlardan.'],
            'dev.body_start' => ['tab' => 'gelistirici', 'label' => '<body> başlangıç kodu', 'type' => 'text', 'default' => '', 'rules' => ['string', 'max:20000'], 'description' => '<body> açılışından hemen sonra (GTM noscript vb.).'],
            'dev.body_end' => ['tab' => 'gelistirici', 'label' => '<body> bitiş kodu', 'type' => 'text', 'default' => '', 'rules' => ['string', 'max:20000'], 'description' => '</body> öncesine.'],
            'dev.custom_meta' => ['tab' => 'gelistirici', 'label' => 'Özel meta etiketleri', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:3000'], 'description' => 'Satır başına "ad=içerik" → <meta name="ad" content="içerik">.'],
            'dev.custom_headers' => ['tab' => 'gelistirici', 'label' => 'Özel HTTP başlıkları', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:3000'], 'description' => 'Satır başına "Ad: değer"; vitrin yanıtlarına eklenir. Güvenlik başlıkları (CSP, HSTS, X-Frame-Options) buradan değiştirilemez.'],
            'dev.preload' => ['tab' => 'gelistirici', 'label' => 'Preload', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:3000'], 'description' => 'Satır başına "adres|tür" (tür: font, image, style, script) → <link rel="preload">.', 'placeholder' => '/fonts/marka.woff2|font'],
            'dev.dns_prefetch' => ['tab' => 'gelistirici', 'label' => 'DNS prefetch', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:2000'], 'description' => 'Satır başına bir köken (https://…).'],
            'dev.preconnect' => ['tab' => 'gelistirici', 'label' => 'Preconnect', 'type' => 'lines', 'default' => [], 'rules' => ['string', 'max:2000'], 'description' => 'Satır başına bir köken (https://…).'],
        ];
    }

    /** @return array{tab: string, label: string, type: string, default: mixed, rules: array<int, string>, description: string, options?: array<int|string, string>, columns?: array<string, array{label: string, type: string, rules: array<int, string>, options?: array<int|string, string>}>, placeholder?: string} */
    public static function definition(string $key): array
    {
        $defs = self::definitions();

        if (! isset($defs[$key])) {
            throw new InvalidArgumentException("Tanımsız SEO ayarı: {$key}");
        }

        return $defs[$key];
    }

    /**
     * Bir sekmenin anahtarları (form ve doğrulama bundan türer).
     *
     * @return array<string, array{tab: string, label: string, type: string, default: mixed, rules: array<int, string>, description: string, options?: array<int|string, string>, columns?: array<string, array{label: string, type: string, rules: array<int, string>, options?: array<int|string, string>}>, placeholder?: string}>
     */
    public static function forTab(string $tab): array
    {
        return array_filter(self::definitions(), fn (array $def) => $def['tab'] === $tab);
    }

    public static function tabExists(string $tab): bool
    {
        return isset(self::TABS[$tab]);
    }

    public static function mode(string $tab): string
    {
        return self::TABS[$tab]['mode'] ?? throw new InvalidArgumentException("Tanımsız sekme: {$tab}");
    }
}
