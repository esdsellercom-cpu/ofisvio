<?php

namespace App\Documents;

use InvalidArgumentException;

/**
 * Belge şablonu tanımları (faz 47): tür başına düzenlenebilir alanlar, yer tutucular ve teknik varsayılanlar.
 * Değerler `document_templates` tablosunda; burada yalnız yapı ve başlangıç metinleri (ticari veri yok —
 * işletme adı/adresi site marka ayarından, müşteri/fatura kayıttan gelir).
 *
 * Yer tutucular metin alanlarında {{placeholder}} biçiminde yazılır; DocumentService::render değiştirir.
 */
final class DocumentTemplates
{
    public const KINDS = ['receipt' => 'Tahsilat makbuzu', 'overdue_notice' => 'Geciken ödeme belgesi'];

    /** Düzenlenebilir alanlar: anahtar → [etiket, tip (text|textarea|url|bool|color|columns), açıklama]. */
    public const FIELDS = [
        'logo_url' => ['label' => 'Logo adresi', 'type' => 'url', 'description' => 'Belgenin sol üstünde; boşsa işletme adı basılır.'],
        'heading' => ['label' => 'Başlık', 'type' => 'text', 'description' => 'Örn. TAHSİLAT MAKBUZU'],
        'subheading' => ['label' => 'Alt başlık', 'type' => 'text', 'description' => 'Belge no ve tarih satırı; yer tutucu kullanılabilir.'],
        'show_business' => ['label' => 'İşletme bloğu', 'type' => 'bool', 'description' => 'Ad, adres, telefon, e-posta (site marka ayarından).'],
        'intro' => ['label' => 'Giriş metni', 'type' => 'textarea', 'description' => 'Tablonun üstündeki açıklama; yer tutucu kullanılabilir.'],
        'columns' => ['label' => 'Tablo sütunları', 'type' => 'columns', 'description' => 'Belge tablosunda gösterilecek alanlar ve sırası.'],
        'body' => ['label' => 'Gövde metni', 'type' => 'textarea', 'description' => 'Tablonun altındaki metin (satır satır); yer tutucu kullanılabilir.'],
        'signature' => ['label' => 'İmza alanı', 'type' => 'text', 'description' => 'Boşsa imza alanı basılmaz.'],
        'stamp' => ['label' => 'Kaşe alanı', 'type' => 'text', 'description' => 'Boşsa kaşe alanı basılmaz.'],
        'footer' => ['label' => 'Alt bilgi', 'type' => 'textarea', 'description' => 'Belgenin en altı (yasal not, iletişim).'],
        'accent' => ['label' => 'Vurgu rengi', 'type' => 'color', 'description' => 'Başlık ve tablo başlığı rengi.'],
    ];

    /**
     * Tür başına yer tutucular (etiketli).
     *
     * @return array<string, string>
     */
    public static function placeholders(string $kind): array
    {
        $common = [
            'business_name' => 'İşletme adı', 'business_legal_name' => 'İşletme tüzel adı', 'business_address' => 'İşletme adresi', 'business_phone' => 'İşletme telefonu', 'business_email' => 'İşletme e-postası',
            'customer_name' => 'Müşteri adı', 'customer_tax_number' => 'Müşteri vergi no', 'customer_email' => 'Müşteri e-postası',
            'invoice_number' => 'Fatura numarası', 'invoice_description' => 'Fatura açıklaması / hizmet', 'invoice_total' => 'Fatura tutarı', 'due_date' => 'Vade tarihi',
            'document_number' => 'Belge numarası', 'date' => 'Belge tarihi', 'currency' => 'Para birimi', 'issuer_name' => 'Düzenleyen',
        ];

        return match ($kind) {
            'receipt' => $common + ['amount' => 'Tahsil edilen tutar', 'amount_words' => 'Tutar (yazıyla)', 'payment_method' => 'Ödeme yöntemi', 'payment_date' => 'Ödeme tarihi', 'payment_reference' => 'Ödeme referansı', 'description' => 'Hizmet / açıklama', 'note' => 'Not', 'remaining_amount' => 'Kalan bakiye', 'paid_amount' => 'Toplam ödenen'],
            'overdue_notice' => $common + ['days_overdue' => 'Gecikme günü', 'amount' => 'Borç (fatura tutarı)', 'paid_amount' => 'Ödenen', 'remaining_amount' => 'Kalan tutar', 'note' => 'Not'],
            default => throw new InvalidArgumentException("Tanımsız belge türü: {$kind}"),
        };
    }

    /**
     * Tablo sütunu seçenekleri (yer tutucu → etiket).
     *
     * @return array<string, string>
     */
    public static function columnOptions(string $kind): array
    {
        return match ($kind) {
            'receipt' => ['invoice_number' => 'Fatura', 'description' => 'Hizmet / açıklama', 'payment_method' => 'Ödeme yöntemi', 'payment_date' => 'Ödeme tarihi', 'amount' => 'Tutar', 'remaining_amount' => 'Kalan'],
            default => ['invoice_number' => 'Fatura', 'invoice_description' => 'Açıklama', 'due_date' => 'Vade', 'days_overdue' => 'Gecikme (gün)', 'amount' => 'Borç', 'paid_amount' => 'Ödenen', 'remaining_amount' => 'Kalan'],
        };
    }

    /**
     * Teknik varsayılan şablon (ilk açılış); panelden değiştirilir.
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $kind): array
    {
        $base = ['logo_url' => '', 'show_business' => true, 'signature' => 'Yetkili imza', 'stamp' => 'Kaşe', 'accent' => '#1f5f4b', 'footer' => '{{business_legal_name}} · {{business_address}} · {{business_phone}} · {{business_email}}'];

        return match ($kind) {
            'receipt' => $base + [
                'heading' => 'TAHSİLAT MAKBUZU',
                'subheading' => 'Makbuz No: {{document_number}} · Tarih: {{date}}',
                'intro' => 'Sayın {{customer_name}}, {{invoice_number}} numaralı faturanıza istinaden aşağıdaki ödeme {{payment_date}} tarihinde {{payment_method}} ile tahsil edilmiştir.',
                'columns' => ['invoice_number', 'description', 'payment_method', 'payment_date', 'amount', 'remaining_amount'],
                'body' => "Tahsil edilen tutar: {{amount}} ({{amount_words}})\nKalan bakiye: {{remaining_amount}}",
            ],
            'overdue_notice' => $base + [
                'heading' => 'GECİKEN ÖDEME BİLDİRİMİ',
                'subheading' => 'Belge No: {{document_number}} · Tarih: {{date}}',
                'intro' => 'Sayın {{customer_name}}, {{invoice_number}} numaralı ve {{due_date}} vadeli faturanızın ödemesi {{days_overdue}} gündür gecikmiştir. Kalan tutarın en kısa sürede ödenmesini rica ederiz.',
                'columns' => ['invoice_number', 'invoice_description', 'due_date', 'days_overdue', 'amount', 'paid_amount', 'remaining_amount'],
                'body' => "Toplam borç: {{amount}}\nÖdenen: {{paid_amount}}\nKalan: {{remaining_amount}}",
            ],
            default => throw new InvalidArgumentException("Tanımsız belge türü: {$kind}"),
        };
    }

    public static function exists(string $kind): bool
    {
        return isset(self::KINDS[$kind]);
    }
}
