<?php

namespace App\Site;

/**
 * Marka renk / tipografi (audit parity P2): panelden yönetilen tasarım değişkenleri. Kaynak `websites.brand_style`
 * (JSON); tema preset'inin (`data-theme`) ÜSTÜNE `:root` değişkeni olarak basılır (nonce'lu <style>). Renkler hex
 * allowlist'i, fontlar sabit listeden (Google Fonts) — serbest CSS/URL kabul edilmez (CSP, enjeksiyon).
 */
final class BrandStyle
{
    /** @var array<string, string> anahtar => CSS değişkeni */
    public const COLORS = ['brand' => '--brand', 'brand_deep' => '--brand-deep', 'brand_light' => '--brand-light', 'brand_wash' => '--brand-wash', 'ink' => '--ink', 'surface' => '--surface'];

    /** @var array<string, array{label: string, family: string, google: string|null}> */
    public const SANS = [
        'instrument' => ['label' => 'Instrument Sans (varsayılan)', 'family' => '"Instrument Sans", "Helvetica Neue", Helvetica, Arial, sans-serif', 'google' => 'Instrument+Sans:wght@400;500;600;700'],
        'inter' => ['label' => 'Inter', 'family' => 'Inter, "Helvetica Neue", Helvetica, Arial, sans-serif', 'google' => 'Inter:wght@400;500;600;700'],
        'manrope' => ['label' => 'Manrope', 'family' => 'Manrope, "Helvetica Neue", Helvetica, Arial, sans-serif', 'google' => 'Manrope:wght@400;500;600;700'],
        'dm' => ['label' => 'DM Sans', 'family' => '"DM Sans", "Helvetica Neue", Helvetica, Arial, sans-serif', 'google' => 'DM+Sans:wght@400;500;600;700'],
        'system' => ['label' => 'Sistem yazı tipi (dış istek yok)', 'family' => 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif', 'google' => null],
    ];

    /** @var array<string, array{label: string, family: string, google: string|null}> */
    public const SERIF = [
        'instrument' => ['label' => 'Instrument Serif (varsayılan)', 'family' => '"Instrument Serif", Georgia, "Times New Roman", serif', 'google' => 'Instrument+Serif:ital@0;1'],
        'playfair' => ['label' => 'Playfair Display', 'family' => '"Playfair Display", Georgia, "Times New Roman", serif', 'google' => 'Playfair+Display:ital,wght@0,400;0,600;1,400'],
        'lora' => ['label' => 'Lora', 'family' => 'Lora, Georgia, "Times New Roman", serif', 'google' => 'Lora:ital,wght@0,400;0,600;1,400'],
        'system' => ['label' => 'Sistem serif (dış istek yok)', 'family' => 'Georgia, "Times New Roman", serif', 'google' => null],
    ];

    /**
     * Girdiyi doğrular; yalnız dolu/geçerli alanlar kalır (boş = tema varsayılanı).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function normalize(array $input): array
    {
        $out = [];

        foreach (array_keys(self::COLORS) as $key) {
            $value = strtolower(trim((string) ($input[$key] ?? '')));

            if (preg_match('/^#[0-9a-f]{6}$/', $value) === 1) {
                $out[$key] = $value;
            }
        }

        foreach (['font_sans' => self::SANS, 'font_serif' => self::SERIF] as $key => $list) {
            $value = (string) ($input[$key] ?? '');

            if ($value !== '' && $value !== 'instrument' && isset($list[$value])) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** `:root { --brand: … }` gövdesi; boşsa ''. @param  array<string, string>|null  $style */
    public static function css(?array $style): string
    {
        $style ??= [];
        $rules = [];

        foreach (self::COLORS as $key => $var) {
            if (isset($style[$key])) {
                $rules[] = $var.':'.$style[$key];
            }
        }

        if (isset($style['font_sans'], self::SANS[$style['font_sans']])) {
            $rules[] = '--font-sans:'.self::SANS[$style['font_sans']]['family'];
        }

        if (isset($style['font_serif'], self::SERIF[$style['font_serif']])) {
            $rules[] = '--font-serif:'.self::SERIF[$style['font_serif']]['family'];
        }

        return $rules === [] ? '' : ':root{'.implode(';', $rules).'}';
    }

    /** Google Fonts sorgu parçaları (family=…); sistem fontu seçildiyse o aile istenmez. @param  array<string, string>|null  $style @return list<string> */
    public static function fontFamilies(?array $style): array
    {
        $style ??= [];
        $out = [];

        foreach (['font_sans' => self::SANS, 'font_serif' => self::SERIF] as $key => $list) {
            $choice = $list[$style[$key] ?? 'instrument'] ?? $list['instrument'];

            if ($choice['google'] !== null) {
                $out[] = 'family='.$choice['google'];
            }
        }

        $out[] = 'family=IBM+Plex+Mono:wght@400;500';

        return $out;
    }
}
