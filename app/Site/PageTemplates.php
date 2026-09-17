<?php

namespace App\Site;

/**
 * Hazır sayfa şablonları (faz 49 "+ Yeni sayfa"): yalnız YAPI — CMS stüdyo blokları (BodyRenderer) ve yer tutucu
 * etiketler. Ticari metin, fiyat, telefon yoktur; editör doldurur.
 */
final class PageTemplates
{
    /** @return array<string, array{label: string, description: string, body: string}> */
    public static function catalog(): array
    {
        return [
            'landing' => [
                'label' => 'Tanıtım sayfası',
                'description' => 'Hero + özellikler + CTA + SSS.',
                'body' => ":::hero\ntitle: Sayfa başlığı\ntext: Kısa açıklama\nbutton: Teklif al\nlink: /iletisim\n:::\n\n## Neler sunuyoruz?\n\n:::features\n- Başlık | Açıklama | /hizmetler\n- Başlık | Açıklama\n- Başlık | Açıklama\n:::\n\n:::cta\ntitle: Hemen başlayın\ntext: Kısa çağrı metni\nbutton: İletişime geçin\nlink: /iletisim\n:::\n\n## Sık sorulanlar\n\n:::faq\n- Soru? | Cevap\n- Soru? | Cevap\n:::",
            ],
            'service' => [
                'label' => 'Hizmet sayfası',
                'description' => 'Açıklama + kapsam listesi + istatistik + SSS + CTA.',
                'body' => "Hizmetin kısa tanımı.\n\n## Kapsam\n\n- Madde\n- Madde\n- Madde\n\n:::stats\n- — | Ölçüt\n- — | Ölçüt\n:::\n\n## Nasıl çalışır?\n\n1. Adım\n2. Adım\n3. Adım\n\n:::faq\n- Soru? | Cevap\n:::\n\n:::cta\ntitle: Teklif alın\nbutton: Teklif al\nlink: /iletisim\n:::",
            ],
            'about' => [
                'label' => 'Hakkımızda',
                'description' => 'Hikâye + değerler + ekip/referans.',
                'body' => "## Biz kimiz?\n\nMetin.\n\n:::box\ntitle: Misyon\ntext: Metin.\n:::\n\n## Değerlerimiz\n\n:::features\n- ✓ | Değer | Açıklama\n- ✓ | Değer | Açıklama\n:::\n\n:::testimonials\n- Ad, Şirket | Görüş\n:::",
            ],
            'contact' => [
                'label' => 'İletişim',
                'description' => 'İletişim bloğu + harita gömme.',
                'body' => ":::contact\ntitle: Bize ulaşın\ntext: Formu doldurun, sizi arayalım.\nbutton: Teklif formu\nlink: /#teklif\n:::\n\n[embed:https://www.google.com/maps/embed?pb=]",
            ],
            'faq' => [
                'label' => 'SSS sayfası',
                'description' => 'Soru–cevap listesi (FAQPage şeması).',
                'body' => "## Sık sorulan sorular\n\n:::faq\n- Soru? | Cevap\n- Soru? | Cevap\n- Soru? | Cevap\n:::",
            ],
        ];
    }
}
