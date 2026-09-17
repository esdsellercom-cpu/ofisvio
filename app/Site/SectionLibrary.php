<?php

namespace App\Site;

/**
 * Bölüm kütüphanesi (master prompt §24–29): ana sayfaya eklenebilen bölüm tipleri,
 * her tipin ayar alanları (panel formu buradan türer) ve veri kaynağı.
 *
 * Veri kaynağı iki türlü: STATİK (bölüm ayarı ya da vitrin metin/blok kaydı) ve
 * DİNAMİK (gerçek varlıklar: lokasyonlar, odalar, yazılar, çözüm blokları). Ticari
 * içerik burada yoktur; yalnız yapı ve etiket.
 *
 * CTA alanı (§29) URL dizesi değildir: {action, target, label} — SiteBuilderService::cta çözer.
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

    /**
     * @return array<string, array{label: string, description: string, source: string, fields: array<string, array{label: string, type: string, options?: array<string, string>}>, unique?: bool}>
     */
    public static function types(): array
    {
        $cta = ['label' => 'CTA', 'type' => 'cta'];

        return [
            'hero' => ['label' => 'Hero', 'description' => 'Başlık, açıklama, süzgeç ve ana görsel.', 'source' => 'Metinler (Ana sayfa) + lokasyonlar + site görseli', 'unique' => true, 'fields' => ['eyebrow' => ['label' => 'Üst etiket (boş: metinler)', 'type' => 'text'], 'title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text'], 'lede' => ['label' => 'Açıklama (boş: metinler)', 'type' => 'textarea'], 'cta' => $cta]],
            'stats' => ['label' => 'İstatistikler', 'description' => 'Lokasyon/şehir/bölge sayıları — veritabanından.', 'source' => 'Lokasyonlar (canlı sayım)', 'unique' => true, 'fields' => []],
            'solutions' => ['label' => 'Çözümler', 'description' => 'Hizmet kartları (Hizmetler modülünden).', 'source' => 'Hizmetler modülü (aktif hizmetler)', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text'], 'lede' => ['label' => 'Açıklama (boş: metinler)', 'type' => 'textarea']]],
            'journey' => ['label' => 'Nasıl çalışır', 'description' => 'Aktivasyon adımları.', 'source' => 'Şirket aktivasyon durum makinesi', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text'], 'lede' => ['label' => 'Açıklama (boş: metinler)', 'type' => 'textarea']]],
            'locations' => ['label' => 'Lokasyonlar', 'description' => 'Yayındaki şubeler, bölge sekmeleri.', 'source' => 'Lokasyonlar (yayında + aktif)', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text']]],
            'meeting' => ['label' => 'Toplantı & Etkinlik', 'description' => 'Gerçek odalar + rezervasyon aracı; oda yoksa basılmaz.', 'source' => 'Odalar (booking engine) + onay politikası', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text'], 'lede' => ['label' => 'Açıklama (boş: metinler)', 'type' => 'textarea']]],
            'amenities' => ['label' => 'Dahil olanlar', 'description' => 'Olanak listesi.', 'source' => 'Vitrin bloğu: amenities', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text']]],
            'pricing' => ['label' => 'Üyelikler', 'description' => 'Plan karşılaştırma tablosu.', 'source' => 'Vitrin blokları: plans, plan_rows, pricing_note', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text']]],
            'blog' => ['label' => 'Yazılar', 'description' => 'Son yayınlanan yazılar; yazı yoksa basılmaz.', 'source' => 'CMS yazıları (yayında)', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text'], 'limit' => ['label' => 'Adet (1–6)', 'type' => 'text']]],
            'lead_form' => ['label' => 'Teklif formu', 'description' => 'Teklif/ön talep formu ve vaatler.', 'source' => 'Metinler + çözüm seçenekleri', 'unique' => true, 'fields' => ['title' => ['label' => 'Başlık (boş: metinler)', 'type' => 'text'], 'lede' => ['label' => 'Açıklama (boş: metinler)', 'type' => 'textarea']]],
            'rich_text' => ['label' => 'Serbest metin', 'description' => 'Başlık + Markdown gövde.', 'source' => 'Bölüm ayarı (statik)', 'fields' => ['eyebrow' => ['label' => 'Üst etiket', 'type' => 'text'], 'title' => ['label' => 'Başlık', 'type' => 'text'], 'body' => ['label' => 'Gövde (Markdown)', 'type' => 'markdown'], 'cta' => $cta]],
            'faq' => ['label' => 'Sık sorulanlar', 'description' => 'Soru–cevap listesi (schema.org FAQPage).', 'source' => 'Bölüm ayarı (statik)', 'fields' => ['title' => ['label' => 'Başlık', 'type' => 'text'], 'items' => ['label' => 'Sorular (her satır: Soru | Cevap)', 'type' => 'lines']]],
            'cta_banner' => ['label' => 'CTA şeridi', 'description' => 'Tek mesaj + düğme.', 'source' => 'Bölüm ayarı (statik)', 'fields' => ['title' => ['label' => 'Mesaj', 'type' => 'text'], 'lede' => ['label' => 'Alt metin', 'type' => 'text'], 'cta' => $cta, 'style' => ['label' => 'Görünüm', 'type' => 'select', 'options' => ['dark' => 'Koyu şerit', 'light' => 'Açık kart']]]],
        ];
    }

    /** @return array{label: string, description: string, source: string, fields: array<string, array{label: string, type: string, options?: array<string, string>}>, unique?: bool} */
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
