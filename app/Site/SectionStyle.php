<?php

namespace App\Site;

/**
 * Bölüm tasarım ayarları (faz 49 görsel editör): cihaz başına (masaüstü / tablet / mobil) allowlist'li
 * stil anahtarları ve alan (metin) biçimleri. Kullanıcı girdisi CSS'e YALNIZ burada, sayısal/sözlük
 * değerlerle çevrilir — serbest CSS/HTML hiçbir zaman geçmez.
 *
 *  settings.style          masaüstü (temel)
 *  settings.style_tablet   ≤ 1024px üstüne yazar
 *  settings.style_mobile   ≤ 640px üstüne yazar
 *  settings.field_styles   { alan: {size, color, weight, italic, align, lh, ls, font} }
 */
final class SectionStyle
{
    public const DEVICES = ['style' => 'desktop', 'style_tablet' => 'tablet', 'style_mobile' => 'mobile'];

    /** anahtar => [tip, kısıt] */
    public const KEYS = [
        'bg' => ['select', ['none', 'surface', 'warm', 'dark', 'brand', 'custom']],
        'bg_color' => ['hex', null],
        'color' => ['hex', null],
        'pt' => ['int', [0, 240]],
        'pb' => ['int', [0, 240]],
        'mt' => ['int', [-120, 240]],
        'mb' => ['int', [-120, 240]],
        'px' => ['int', [0, 120]],
        'align' => ['select', ['left', 'center', 'right']],
        'max_width' => ['int', [320, 1800]],
        'radius' => ['int', [0, 64]],
        'shadow' => ['select', ['none', 'sm', 'md', 'lg']],
        'border' => ['select', ['none', 'line', 'brand']],
        'cols' => ['int', [1, 4]],
        'minh' => ['int', [0, 1200]],
        'zindex' => ['int', [0, 20]],
        'hidden' => ['bool', null],
    ];

    public const FIELD_KEYS = [
        'size' => ['int', [10, 120]],
        'color' => ['hex', null],
        'weight' => ['select', ['400', '500', '600', '700', '800']],
        'italic' => ['bool', null],
        'align' => ['select', ['left', 'center', 'right']],
        'lh' => ['float', [0.9, 2.6]],
        'ls' => ['float', [-3, 12]],
        'font' => ['select', ['sans', 'serif', 'mono']],
    ];

    /** @return array<string, mixed> */
    public static function normalize(mixed $raw, bool $field = false): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($field ? self::FIELD_KEYS : self::KEYS as $key => [$type, $constraint]) {
            if (! array_key_exists($key, $raw) || $raw[$key] === '' || $raw[$key] === null) {
                continue;
            }

            $value = $raw[$key];

            switch ($type) {
                case 'select':
                    if (in_array((string) $value, (array) $constraint, true)) {
                        $out[$key] = (string) $value;
                    }
                    break;
                case 'hex':
                    if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
                        $out[$key] = strtolower($value);
                    }
                    break;
                case 'int':
                    if (is_numeric($value)) {
                        $out[$key] = max((int) $constraint[0], min((int) $constraint[1], (int) $value));
                    }
                    break;
                case 'float':
                    if (is_numeric($value)) {
                        $out[$key] = round(max((float) $constraint[0], min((float) $constraint[1], (float) $value)), 2);
                    }
                    break;
                case 'bool':
                    if (filter_var($value, FILTER_VALIDATE_BOOL)) {
                        $out[$key] = true;
                    }
                    break;
            }
        }

        return $out;
    }

    /**
     * Alan biçimleri: { alan: {...} } → yalnız izinli anahtarlar.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function normalizeFields(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $field => $style) {
            if (is_string($field) && preg_match('/^[a-z0-9_]{1,30}$/', $field) === 1) {
                $n = self::normalize($style, true);

                if ($n !== []) {
                    $out[$field] = $n;
                }
            }
        }

        return $out;
    }

    /** Sarmalayıcıya inline CSS değişkenleri (masaüstü). */
    public static function inline(array $style): string
    {
        $css = [];

        foreach (self::declarations($style) as $prop => $value) {
            $css[] = $prop.':'.$value;
        }

        return implode(';', $css);
    }

    /**
     * Tablet/mobil üstüne yazmalar: `#sec-ID{…}` seçicili @media blokları (yalnız değer varsa).
     *
     * @param  array<string, mixed>  $settings
     */
    public static function media(array $settings, string $selector): string
    {
        $out = '';

        foreach (['style_tablet' => '(max-width: 1024px)', 'style_mobile' => '(max-width: 640px)'] as $key => $query) {
            $decl = self::declarations((array) ($settings[$key] ?? []));

            if ($decl === []) {
                continue;
            }

            $out .= '@media '.$query.'{'.$selector.'{'.implode(';', array_map(fn ($p, $v) => $p.':'.$v.' !important', array_keys($decl), $decl)).'}}';
        }

        return $out;
    }

    /** CSS sınıfları (arka plan/gölge/kenarlık sözlük değerleri). */
    public static function classes(array $style): string
    {
        $classes = [];

        foreach (['bg', 'shadow', 'border'] as $key) {
            if (isset($style[$key]) && $style[$key] !== 'none') {
                $classes[] = 'sec-'.$key.'-'.$style[$key];
            }
        }

        if (! empty($style['hidden'])) {
            $classes[] = 'sec-hidden';
        }

        return implode(' ', $classes);
    }

    /** Alan (metin) biçimi → inline style. */
    public static function fieldInline(array $fs): string
    {
        $css = [];

        if (isset($fs['size'])) {
            $css[] = 'font-size:'.$fs['size'].'px';
        }
        if (isset($fs['color'])) {
            $css[] = 'color:'.$fs['color'];
        }
        if (isset($fs['weight'])) {
            $css[] = 'font-weight:'.$fs['weight'];
        }
        if (! empty($fs['italic'])) {
            $css[] = 'font-style:italic';
        }
        if (isset($fs['align'])) {
            $css[] = 'text-align:'.$fs['align'];
        }
        if (isset($fs['lh'])) {
            $css[] = 'line-height:'.$fs['lh'];
        }
        if (isset($fs['ls'])) {
            $css[] = 'letter-spacing:'.$fs['ls'].'px';
        }
        if (isset($fs['font'])) {
            $css[] = 'font-family:var(--font-'.$fs['font'].')';
        }

        return implode(';', $css);
    }

    /** @return array<string, string> */
    private static function declarations(array $style): array
    {
        $d = [];

        foreach (['pt', 'pb', 'mt', 'mb', 'px', 'minh'] as $k) {
            if (isset($style[$k])) {
                $d['--sec-'.$k] = ((int) $style[$k]).'px';
            }
        }

        if (isset($style['max_width'])) {
            $d['--sec-maxw'] = ((int) $style['max_width']).'px';
        }
        if (isset($style['radius'])) {
            $d['--sec-radius'] = ((int) $style['radius']).'px';
        }
        if (isset($style['align'])) {
            $d['--sec-align'] = (string) $style['align'];
        }
        if (isset($style['cols'])) {
            $d['--sec-cols'] = (string) (int) $style['cols'];
        }
        if (isset($style['zindex'])) {
            $d['--sec-z'] = (string) (int) $style['zindex'];
        }
        $presets = ['surface' => 'var(--surface)', 'warm' => 'var(--surface-warm, #f6f4ef)', 'dark' => 'var(--dark, #14201b)', 'brand' => 'var(--brand)'];

        if (($style['bg'] ?? '') === 'custom' && isset($style['bg_color'])) {
            $d['--sec-bg'] = (string) $style['bg_color'];
        } elseif (isset($presets[$style['bg'] ?? ''])) {
            $d['--sec-bg'] = $presets[$style['bg']];

            if (! isset($style['color'])) {
                $d['--sec-color'] = match ($style['bg']) {
                    'dark' => 'var(--dark-ink, #f4f1ea)', 'brand' => '#fff', default => 'inherit'
                };
            }
        }
        if (isset($style['color'])) {
            $d['--sec-color'] = (string) $style['color'];
        }

        return $d;
    }
}
