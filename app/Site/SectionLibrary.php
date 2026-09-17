<?php

namespace App\Site;

/**
 * Bölüm kütüphanesi (master prompt §24–29 + görsel editör faz 49): ana sayfaya eklenebilen bölüm tipleri,
 * her tipin ayar alanları (panel formu ve editör paneli buradan türer), veri kaynağı ve paletteki grubu.
 *
 * Veri kaynağı iki türlü: STATİK (bölüm ayarı ya da vitrin metin/blok kaydı) ve
 * DİNAMİK (gerçek varlıklar: lokasyonlar, odalar, yazılar, hizmetler). Ticari
 * içerik burada yoktur; yalnız yapı ve etiket.
 *
 * Alan tipleri: text · textarea · markdown · lines · select · cta · media (medya id) · media_list (id listesi) · number.
 * CTA alanı (§29) URL dizesi değildir: {action, target, label} — SiteBuilderService::cta çözer.
 * Her bölümün ayrıca `style`/`style_tablet`/`style_mobile` (SectionStyle) ve `field_styles` (metin biçimi) ayarı vardır.
 */
final class SectionLibrary
{
    public const CTA_ACTIONS = [
        'anchor' => 'Sayfa içi bölüm (#…)',
        'page' => 'Site sayfası (yol)',
        'booking' => 'Rezervasyon akışı',
        'lead_form' => 'Teklif formu',
        'locations' => 'Lokasyonlar sayfası',
        'blog' => 'Yazılar',
        'phone' => 'Telefon (site ayarı)',
        'whatsapp' => 'WhatsApp (site ayarı)',
        'email' => 'E-posta (site ayarı)',
        'url' => 'Dış bağlantı (https)',
        'none' => 'Bağlantı yok',
    ];

    /** Palet grupları (editör sol paneli). */
    public const GROUPS = ['temel' => 'Temel', 'icerik' => 'İçerik', 'yerlesim' => 'Yerleşim', 'veri' => 'Gerçek veri'];

    /**
     * @return array<string, array{label: string, description: string, source: string, group: string, icon: string, fields: array<string, array{label: string, type: string, options?: array<string, string>, hint?: string}>, unique?: bool, texts?: array<string, string>}>
     */
    public static function types(): array
    {
        $cta = ['label' => 'CTA', 'type' => 'cta'];

        return [
            // ---- Gerçek veri (dinamik) — mevcut bölümler; `texts` = alan boşken okunan global metin anahtarı (editör inline düzenler)
            'hero' => ['label' => 'Hero', 'description' => 'Başlık, açıklama, süzgeç ve ana görsel.', 'source' => 'Metinler (Ana sayfa) + lokasyonlar + site görseli', 'group' => 'icerik', 'icon' => '▣', 'unique' => true, 'fields' => ['eyebrow' => ['label' => 'Üst etiket (boş: metinler)', 'type' => 'text'], 'title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text'], 'lede' => ['label' => 'Açıklama (boş: metinler)', 'type' => 'textarea'], 'cta' => $cta], 'texts' => ['eyebrow' => 'hero_eyebrow', 'lede' => 'hero_lede']],
            'stats' => ['label' => 'İstatistikler', 'description' => 'Lokasyon/şehir/bölge sayıları — veritabanından.', 'source' => 'Lokasyonlar (canlı sayım)', 'group' => 'veri', 'icon' => '▮▮', 'unique' => true, 'fields' => []],
            'solutions' => ['label' => 'Çözümler (hizmetler)', 'description' => 'Hizmet kartları (Hizmetler modülünden).', 'source' => 'Hizmetler modülü (aktif hizmetler)', 'group' => 'veri', 'icon' => '◫', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text'], 'lede' => ['label' => 'Açıklama (boş: metinler)', 'type' => 'textarea']], 'texts' => ['title' => 'solutions_title', 'lede' => 'solutions_lede']],
            'journey' => ['label' => 'Nasıl çalışır', 'description' => 'Aktivasyon adımları.', 'source' => 'Şirket aktivasyon durum makinesi', 'group' => 'veri', 'icon' => '➊', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text'], 'lede' => ['label' => 'Açıklama (boş: metinler)', 'type' => 'textarea']], 'texts' => ['title' => 'journey_title', 'lede' => 'journey_lede']],
            'locations' => ['label' => 'Lokasyonlar', 'description' => 'Yayındaki şubeler, bölge sekmeleri.', 'source' => 'Lokasyonlar (yayında + aktif)', 'group' => 'veri', 'icon' => '◎', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text']], 'texts' => ['title' => 'locations_title']],
            'meeting' => ['label' => 'Toplantı & Etkinlik', 'description' => 'Gerçek odalar + rezervasyon aracı; oda yoksa basılmaz.', 'source' => 'Odalar (booking engine) + onay politikası', 'group' => 'veri', 'icon' => '◧', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text'], 'lede' => ['label' => 'Açıklama (boş: metinler)', 'type' => 'textarea']], 'texts' => ['title' => 'meeting_title', 'lede' => 'meeting_lede']],
            'amenities' => ['label' => 'Dahil olanlar', 'description' => 'Olanak listesi.', 'source' => 'Vitrin bloğu: amenities', 'group' => 'veri', 'icon' => '✓', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text']], 'texts' => ['title' => 'amenities_title']],
            'pricing' => ['label' => 'Üyelikler', 'description' => 'Plan karşılaştırma tablosu.', 'source' => 'Vitrin blokları: plans, plan_rows, pricing_note', 'group' => 'veri', 'icon' => '▤', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text']], 'texts' => ['title' => 'pricing_title']],
            'blog' => ['label' => 'Blog', 'description' => 'Son yayınlanan yazılar; yazı yoksa basılmaz.', 'source' => 'CMS yazıları (yayında)', 'group' => 'veri', 'icon' => '▭', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text'], 'limit' => ['label' => 'Adet (1–6)', 'type' => 'text']], 'texts' => ['title' => 'blog_title']],
            'lead_form' => ['label' => 'İletişim / Teklif formu', 'description' => 'Teklif/ön talep formu ve vaatler.', 'source' => 'Metinler + çözüm seçenekleri', 'group' => 'icerik', 'icon' => '✉', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text'], 'lede' => ['label' => 'Açıklama (boş: metinler)', 'type' => 'textarea']], 'texts' => ['title' => 'lead_title', 'lede' => 'lead_lede']],

            // ---- Statik içerik
            'rich_text' => ['label' => 'Metin', 'description' => 'Başlık + Markdown gövde + CTA.', 'source' => 'Bölüm ayarı (statik)', 'group' => 'temel', 'icon' => '¶', 'fields' => ['eyebrow' => ['label' => 'Üst etiket', 'type' => 'text'], 'title' => ['label' => 'Başlık', 'type' => 'text'], 'body' => ['label' => 'Gövde (Markdown)', 'type' => 'markdown'], 'cta' => $cta]],
            'heading' => ['label' => 'Başlık', 'description' => 'Tek başlık satırı (H2/H3) + alt metin.', 'source' => 'Bölüm ayarı (statik)', 'group' => 'temel', 'icon' => 'H', 'fields' => ['title' => ['label' => 'Başlık', 'type' => 'text'], 'lede' => ['label' => 'Alt metin', 'type' => 'textarea'], 'level' => ['label' => 'Düzey', 'type' => 'select', 'options' => ['h2' => 'H2', 'h3' => 'H3']]]],
            'image' => ['label' => 'Görsel', 'description' => 'Medya kütüphanesinden tek görsel; açıklama ve bağlantı.', 'source' => 'Medya kütüphanesi', 'group' => 'temel', 'icon' => '🖼', 'fields' => ['media' => ['label' => 'Görsel', 'type' => 'media'], 'caption' => ['label' => 'Açıklama (caption)', 'type' => 'text'], 'link' => ['label' => 'Bağlantı (/yol ya da https)', 'type' => 'text'], 'fit' => ['label' => 'Sığdırma', 'type' => 'select', 'options' => ['cover' => 'Kırp (cover)', 'contain' => 'Sığdır (contain)']], 'ratio' => ['label' => 'Oran', 'type' => 'select', 'options' => ['auto' => 'Doğal', '16/9' => '16:9', '4/3' => '4:3', '1/1' => '1:1', '4/5' => '4:5']], 'width' => ['label' => 'Genişlik (%)', 'type' => 'number']]],
            'buttons' => ['label' => 'Buton / Bağlantı', 'description' => 'Bir–üç düğme yan yana.', 'source' => 'Bölüm ayarı (statik)', 'group' => 'temel', 'icon' => '⬚', 'fields' => ['cta' => ['label' => 'Birinci düğme', 'type' => 'cta'], 'cta2' => ['label' => 'İkinci düğme', 'type' => 'cta'], 'cta3' => ['label' => 'Üçüncü düğme', 'type' => 'cta'], 'variant' => ['label' => 'Görünüm', 'type' => 'select', 'options' => ['brand' => 'Dolu', 'ghost' => 'Çerçeveli', 'link' => 'Bağlantı']]]],
            'divider' => ['label' => 'Ayırıcı', 'description' => 'İnce çizgi.', 'source' => 'Bölüm ayarı (statik)', 'group' => 'temel', 'icon' => '—', 'fields' => []],
            'spacer' => ['label' => 'Boşluk', 'description' => 'Dikey boşluk (px).', 'source' => 'Bölüm ayarı (statik)', 'group' => 'yerlesim', 'icon' => '↕', 'fields' => ['height' => ['label' => 'Yükseklik (px)', 'type' => 'number']]],
            'columns' => ['label' => 'Kolonlar (satır)', 'description' => 'Yan yana 2–4 kolon; her kolon Markdown. Cihaz başına kolon sayısı tasarım sekmesinden.', 'source' => 'Bölüm ayarı (statik)', 'group' => 'yerlesim', 'icon' => '▦', 'fields' => ['title' => ['label' => 'Başlık', 'type' => 'text'], 'col1' => ['label' => 'Kolon 1 (Markdown)', 'type' => 'markdown'], 'col2' => ['label' => 'Kolon 2 (Markdown)', 'type' => 'markdown'], 'col3' => ['label' => 'Kolon 3 (Markdown)', 'type' => 'markdown'], 'col4' => ['label' => 'Kolon 4 (Markdown)', 'type' => 'markdown']]],
            'content' => ['label' => 'İçerik bloğu', 'description' => 'CMS stüdyo blokları (:::hero, :::features, [youtube:…] …) — tam Markdown.', 'source' => 'Bölüm ayarı (statik)', 'group' => 'yerlesim', 'icon' => '⧉', 'fields' => ['body' => ['label' => 'Gövde (Markdown + bloklar)', 'type' => 'markdown']]],
            'features' => ['label' => 'Özellikler', 'description' => 'İkon + başlık + açıklama kartları.', 'source' => 'Bölüm ayarı (statik)', 'group' => 'icerik', 'icon' => '✦', 'fields' => ['title' => ['label' => 'Başlık', 'type' => 'text'], 'lede' => ['label' => 'Açıklama', 'type' => 'textarea'], 'items' => ['label' => 'Maddeler (her satır: İkon | Başlık | Açıklama | /bağlantı)', 'type' => 'lines', 'hint' => 'İkon: emoji ya da tek harf; bağlantı isteğe bağlı.']]],
            'testimonials' => ['label' => 'Referanslar', 'description' => 'Müşteri görüşleri.', 'source' => 'Bölüm ayarı (statik)', 'group' => 'icerik', 'icon' => '❝', 'fields' => ['title' => ['label' => 'Başlık', 'type' => 'text'], 'items' => ['label' => 'Görüşler (her satır: Ad | Şirket/Rol | Görüş)', 'type' => 'lines']]],
            'gallery' => ['label' => 'Galeri', 'description' => 'Medya kütüphanesinden görsel ızgarası.', 'source' => 'Medya kütüphanesi', 'group' => 'icerik', 'icon' => '▦', 'fields' => ['title' => ['label' => 'Başlık', 'type' => 'text'], 'media' => ['label' => 'Görseller', 'type' => 'media_list'], 'ratio' => ['label' => 'Oran', 'type' => 'select', 'options' => ['4/3' => '4:3', '1/1' => '1:1', '16/9' => '16:9', '3/4' => '3:4']]]],
            'map' => ['label' => 'Harita', 'description' => 'Google Haritalar gömme (yalnız izinli kaynak).', 'source' => 'Bölüm ayarı (statik)', 'group' => 'icerik', 'icon' => '⌖', 'fields' => ['title' => ['label' => 'Başlık', 'type' => 'text'], 'embed' => ['label' => 'Gömme adresi (https://www.google.com/maps/embed?…)', 'type' => 'text'], 'address' => ['label' => 'Adres metni', 'type' => 'text']]],
            'faq' => ['label' => 'Sık sorulanlar', 'description' => 'Soru–cevap listesi (schema.org FAQPage).', 'source' => 'Bölüm ayarı (statik)', 'group' => 'icerik', 'icon' => '?', 'fields' => ['title' => ['label' => 'Başlık', 'type' => 'text'], 'items' => ['label' => 'Sorular (her satır: Soru | Cevap)', 'type' => 'lines']]],
            'cta_banner' => ['label' => 'CTA şeridi', 'description' => 'Tek mesaj + düğme.', 'source' => 'Bölüm ayarı (statik)', 'group' => 'icerik', 'icon' => '➤', 'fields' => ['title' => ['label' => 'Mesaj', 'type' => 'text'], 'lede' => ['label' => 'Alt metin', 'type' => 'text'], 'cta' => $cta, 'style' => ['label' => 'Görünüm', 'type' => 'select', 'options' => ['dark' => 'Koyu şerit', 'light' => 'Açık kart']]]],
        ];
    }

    /** @return array{label: string, description: string, source: string, group: string, icon: string, fields: array<string, array{label: string, type: string, options?: array<string, string>, hint?: string}>, unique?: bool, texts?: array<string, string>} */
    public static function type(string $key): array
    {
        $types = self::types();

        if (! isset($types[$key])) {
            throw new \InvalidArgumentException("Tanımsız bölüm tipi: {$key}");
        }

        return $types[$key];
    }

    public static function exists(string $key): bool
    {
        return isset(self::types()[$key]);
    }

    /**
     * Editörde "ekle" anında gösterilecek varsayılan ayarlar — yalnız yapı/etiket, ticari veri yok.
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $type): array
    {
        return match ($type) {
            'rich_text' => ['title' => 'Başlık', 'body' => 'Metninizi buraya yazın.'],
            'heading' => ['title' => 'Başlık', 'level' => 'h2'],
            'buttons' => ['cta' => ['action' => 'lead_form', 'target' => '', 'label' => 'Teklif al'], 'variant' => 'brand'],
            'spacer' => ['height' => '48'],
            'columns' => ['col1' => "**Kolon 1**\n\nMetin.", 'col2' => "**Kolon 2**\n\nMetin."],
            'content' => ['body' => ":::box\ntitle: Başlık\ntext: Metin.\n:::"],
            'features' => ['title' => 'Özellikler', 'items' => ['✓ | Özellik | Açıklama', '✓ | Özellik | Açıklama', '✓ | Özellik | Açıklama']],
            'testimonials' => ['title' => 'Referanslar', 'items' => ['Ad Soyad | Şirket | Görüş metni']],
            'faq' => ['title' => 'Sık sorulanlar', 'items' => ['Soru? | Cevap']],
            'cta_banner' => ['title' => 'Mesaj', 'cta' => ['action' => 'lead_form', 'target' => '', 'label' => 'Teklif al'], 'style' => 'dark'],
            'image' => ['fit' => 'cover', 'ratio' => 'auto', 'width' => '100'],
            'gallery' => ['ratio' => '4/3'],
            'map' => ['title' => 'Harita'],
            default => [],
        };
    }

    /**
     * Varsayılan yerleşim (kurulumdaki sıra); ticari içerik yok, yalnız yapı.
     *
     * @return array<int, array{type: string, anchor: string|null}>
     */
    public static function defaultLayout(): array
    {
        return [
            ['type' => 'hero', 'anchor' => null],
            ['type' => 'stats', 'anchor' => null],
            ['type' => 'solutions', 'anchor' => 'cozumler'],
            ['type' => 'journey', 'anchor' => 'nasil'],
            ['type' => 'locations', 'anchor' => 'lokasyonlar'],
            ['type' => 'meeting', 'anchor' => 'toplanti'],
            ['type' => 'amenities', 'anchor' => 'dahil'],
            ['type' => 'pricing', 'anchor' => 'uyelik'],
            ['type' => 'blog', 'anchor' => 'yazilar'],
            ['type' => 'lead_form', 'anchor' => 'teklif'],
        ];
    }
}
