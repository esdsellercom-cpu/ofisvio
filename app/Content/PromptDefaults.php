<?php

namespace App\Content;

/**
 * Varsayılan prompt'lar (faz 60e): ilk kullanımda Prompt Registry'ye sürüm 1 olarak yazılır; panelden yeni sürüm
 * açılır, eski sürümler iş kayıtlarında referans olarak kalır. Teknik metin — ticari veri taşımaz. Yer tutucular:
 * {topic} {brief} {brand} {services} {locations} {keywords} {body} {title} {city}.
 */
class PromptDefaults
{
    public const SYSTEM = 'Sen Ofisvio için Türkçe içerik yazan bir editörsün. Yalnız verilen marka, hizmet ve lokasyon bilgilerini kullan; fiyat, adres, telefon, istatistik, yorum ya da kişi UYDURMA. Emin olmadığın olgusal iddiaları yazma. Doğrudan, açık, kurumsal ama sıcak bir dil kullan. Yanıtı YALNIZ geçerli JSON olarak ver.';

    /** @return array<string, array{name: string, system: string, template: string}> */
    public static function all(): array
    {
        return [
            'draft' => [
                'name' => 'Makale taslağı (SEO + GEO)',
                'system' => self::SYSTEM,
                'template' => <<<'TXT'
Konu: {topic}
Brief: {brief}
Hedef anahtar kelimeler: {keywords}

Marka bilgisi (gerçek): {brand}
Hizmetler (gerçek): {services}
Lokasyonlar (gerçek): {locations}

Görev: Bu konu için 900–1400 kelimelik, Markdown gövdeli bir blog yazısı üret. Gövde "## " ile başlayan 4–6 alt başlık içersin; sonunda "## Sık sorulan sorular" altında en az 3 "### Soru?" başlığı ve cevabı olsun. Bir alt başlık altında hizmetle ilgili gerçek bilgileri (verilen listeden) kullan; iç bağlantı için hizmet adlarını olduğu gibi geçir. Rakam, fiyat, telefon, adres YAZMA; gerekiyorsa "güncel fiyat için hizmet sayfasına bakın" de.

Yanıt JSON alanları: title (≤ 60 karakter), excerpt (120–160 karakter), body (Markdown), meta_title (≤ 60), meta_description (120–160), faq ([{q,a}] en az 3), tags ([] en fazla 5 küçük harf).
TXT,
            ],
            'refresh' => [
                'name' => 'İçerik yenileme (mevcut yazıyı güncelle)',
                'system' => self::SYSTEM,
                'template' => <<<'TXT'
Yenilenecek yazı başlığı: {title}
Yenileme gerekçeleri: {brief}
Hedef anahtar kelimeler: {keywords}
Marka bilgisi (gerçek): {brand}
Hizmetler (gerçek): {services}
Lokasyonlar (gerçek): {locations}

Mevcut gövde (Markdown):
{body}

Görev: Yazıyı yapısını ve doğru bilgilerini koruyarak güncelle: eskimiş ifadeleri ("geçen yıl", eski yıl vurguları) kaldır, eksik alt başlıkları tamamla, giriş paragrafını netleştir, SSS bölümü yoksa ekle. Yeni olgusal iddia, rakam, fiyat, adres EKLEME. Bağlantıları (markdown) olduğu gibi koru.

Yanıt JSON alanları: title, excerpt, body (Markdown), meta_title, meta_description, faq ([{q,a}]), change_summary (yapılan değişikliklerin 3–6 maddelik listesi).
TXT,
            ],
            'fact_check' => [
                'name' => 'Doğruluk kontrolü — iddia çıkarımı',
                'system' => 'Sen bir olgu denetçisisin. Metindeki doğrulanması gereken olgusal iddiaları (rakam, tarih, yasal kural, fiyat, karşılaştırma, "en/ilk/tek" gibi mutlak ifadeler) listele. Yanıtı YALNIZ JSON ver.',
                'template' => <<<'TXT'
Metin:
{body}

Yanıt JSON: claims ([{claim, type, risk: low|medium|high, why}]) — en fazla 15 madde; iddia yoksa boş dizi.
TXT,
            ],
        ];
    }
}
