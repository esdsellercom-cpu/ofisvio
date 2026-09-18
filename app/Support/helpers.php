<?php

use App\Site\SectionStyle;
use App\Support\Money;
use Illuminate\Support\HtmlString;

if (! function_exists('money')) {
    /** Kuruş → biçimli tutar + simge (para birimi ayardan). Blade: {{ money($invoice->total) }} */
    function money(int|float|null $minor, ?string $currency = null): string
    {
        return Money::format($minor, $currency);
    }
}

if (! function_exists('asset_v')) {
    /**
     * Sürümlü public asset adresi (faz 46): dosya değişince ?v= değişir, tarayıcı/CDN eski kopyayı kullanmaz.
     * Vite derlemesi olmayan public/css ve public/js için.
     */
    function asset_v(string $path): string
    {
        $file = public_path($path);
        $version = is_file($file) ? (string) filemtime($file) : '0';

        return asset($path).'?v='.$version;
    }
}

if (! function_exists('ofv_editor')) {
    /** Görsel editör çerçevesi mi (faz 49)? Yalnız imzalı önizleme `?editor=1` ile (istek özniteliği); canlı vitrinde daima false. */
    function ofv_editor(): bool
    {
        return app()->bound('request') && request()->attributes->get('ofv.editor') === true;
    }
}

if (! function_exists('ofv')) {
    /**
     * Düzenlenebilir alan işareti + alan biçimi. Editör dışında yalnız biçim (varsa) basılır; canlıya editör
     * öznitelikleri gitmez. `$global` = bölüm dışı global metin anahtarı (texts.cta_header gibi).
     *
     * @param  array<string, mixed>  $s  bölüm ayarları
     */
    function ofv(array $s, string $field, ?string $global = null): HtmlString
    {
        $attrs = '';
        $style = SectionStyle::fieldInline((array) ($s['field_styles'][$field] ?? []));

        if ($style !== '') {
            $attrs .= ' style="'.e($style).'"';
        }

        if (ofv_editor()) {
            $attrs .= ' data-ofv-field="'.e($field).'"'.($global !== null ? ' data-ofv-global="'.e($global).'"' : '');
        }

        return new HtmlString($attrs);
    }
}

if (! function_exists('ofv_item')) {
    /** Tekrarlı madde (lines alanı) hücresi: yalnız editörde işaretlenir — alan:satır:sütun (faz 57). */
    function ofv_item(string $field, int $index, int $column): HtmlString
    {
        return new HtmlString(ofv_editor() ? ' data-ofv-item="'.e($field).':'.$index.':'.$column.'"' : '');
    }
}

if (! function_exists('ofv_global')) {
    /** Bölüm dışı (header/footer/üst şerit) global metin: yalnız editörde işaretlenir. */
    function ofv_global(string $key): HtmlString
    {
        return new HtmlString(ofv_editor() ? ' data-ofv-global="'.e($key).'"' : '');
    }
}
