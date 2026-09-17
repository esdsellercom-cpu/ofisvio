<?php

namespace App\Support;

use App\Services\SettingsService;

/**
 * Para (audit P0-3): tüm tutarlar veritabanında ve serviste MINOR UNIT (kuruş, cent)
 * tam sayıdır — float yok, yuvarlama yalnız burada. Gösterim para birimi ayardan
 * (general.currency). Formlar büyük birim ("1.190,50") alır, parse() kuruşa çevirir.
 */
final class Money
{
    private const SYMBOLS = ['TRY' => '₺', 'EUR' => '€', 'USD' => '$'];

    /** Doğrulama kuralı: büyük birim, en fazla iki ondalık; "1190", "1190,50", "1190.50", "1.190,50". */
    public const RULE = 'regex:/^\d{1,3}(\.\d{3})*([,]\d{1,2})?$|^\d{1,9}([.,]\d{1,2})?$/';

    private static ?string $currency = null;

    /** Kuruş → "1.190,50" + para birimi simgesi; her zaman iki ondalık (finansal tutarlılık). */
    public static function format(int|float|null $minor, ?string $currency = null): string
    {
        $currency ??= self::currency();

        return number_format(((int) $minor) / 100, 2, ',', '.').' '.(self::SYMBOLS[$currency] ?? $currency);
    }

    /**
     * Form girdisi → kuruş: "1.190,50", "1190,50", "1190.50", "1190" hepsi 119050.
     * Binlik ayıracı ve ondalık işareti birlikte varsa son ayıraç ondalıktır.
     */
    public static function parse(string|int|float|null $input): int
    {
        if ($input === null || $input === '') {
            return 0;
        }

        if (is_int($input)) {
            return $input * 100;
        }

        $s = trim((string) $input);
        $s = preg_replace('/[^\d,.\-]/', '', $s) ?? '';
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $s = str_replace('.', '', $s);       // 1.190,50 → 1190,50
            $s = str_replace(',', '.', $s);      // → 1190.50
        } elseif ($lastDot !== false && $lastComma !== false) {
            $s = str_replace(',', '', $s);       // 1,190.50 → 1190.50
        } elseif ($lastDot !== false && (substr_count($s, '.') > 1 || preg_match('/^\d{1,3}\.\d{3}$/', $s) === 1)) {
            $s = str_replace('.', '', $s);       // 1.190.000 / 1.190 (Türkçe binlik) → 1190000 / 1190
        }

        if (! is_numeric($s)) {
            return 0;
        }

        return (int) round(((float) $s) * 100);
    }

    /** Form değeri için büyük birim ("1190.50") — input[type=number] step=0.01 ile. */
    public static function major(int|float|null $minor): string
    {
        return number_format(((int) $minor) / 100, 2, '.', '');
    }

    /** Yüzde uygula (KDV vb.): kuruş tam sayı, yarım yukarı yuvarlama. */
    public static function percent(int $minor, int|float $rate): int
    {
        return (int) round($minor * $rate / 100);
    }

    public static function currency(): string
    {
        return self::$currency ??= app(SettingsService::class)->string('general.currency');
    }

    /** Ayar değişince (SettingsService::set) ya da testte memo sıfırlanır. */
    public static function forgetCurrency(): void
    {
        self::$currency = null;
    }
}
