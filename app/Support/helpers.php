<?php

use App\Services\SettingsService;
use App\Site\SectionStyle;
use App\Support\Csp;
use App\Support\Money;
use Illuminate\Support\HtmlString;

if (! function_exists('money')) {
    /** Kuruş → biçimli tutar + simge (para birimi ayardan). Blade: {{ money($invoice->total) }} */
    function money(int|float|null $minor, ?string $currency = null): string
    {
        return Money::format($minor, $currency);
    }
}

if (! function_exists('developer_credit')) {
    /** Geliştirici/attribution metni — merkezi ayar `general.developer_credit` (panel › Ayarlar › Genel); boş = basılmaz. */
    function developer_credit(): string
    {
        return trim(app(SettingsService::class)->string('general.developer_credit'));
    }
}

if (! function_exists('money_symbol')) {
    /** Para birimi simgesi (ayardan) — form etiketleri: Tutar ({{ money_symbol() }}) */
    function money_symbol(): string
    {
        return Money::symbol();
    }
}

if (! function_exists('csp_nonce')) {
    /** CSP nonce (audit F-11): satır içi <script nonce="{{ csp_nonce() }}">; SecurityHeaders aynı değeri başlığa yazar. */
    function csp_nonce(): string
    {
        return Csp::nonce();
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

if (! function_exists('ofv_live')) {
    /**
     * Canlı düzenleme (faz 59) açık mı? Yalnız yetkili oturumda + oturum bayrağı açıkken; SiteLayoutComposer istek
     * özniteliğine yazar. Ziyaretçide ve editör çerçevesinde daima false — canlıya işaret/JS gitmez.
     */
    function ofv_live(): bool
    {
        return app()->bound('request') && request()->attributes->get('ofv.live') === true && ! ofv_editor();
    }
}

if (! function_exists('ofv_le')) {
    /**
     * Canlı düzenlenebilir görsel işareti: data-le="kind:id:field[:index]" + etiket + mevcut medya + alt önerisi.
     * Yalnız canlı düzenleme açık ve kullanıcı o hedef türü için yetkiliyse basılır (composer 'ofv.live.can').
     */
    function ofv_le(string $kind, int $id, string $field, ?int $index, string $label, ?int $mediaId = null, string $altSuggest = ''): HtmlString
    {
        if (! ofv_live() || $id <= 0) {
            return new HtmlString('');
        }

        $can = (array) request()->attributes->get('ofv.live.can', []);

        if (empty($can[$kind])) {
            return new HtmlString('');
        }

        $target = $kind.':'.$id.':'.$field.($index !== null ? ':'.$index : '');

        return new HtmlString(' data-le="'.e($target).'" data-le-label="'.e($label).'" data-le-media="'.e((string) ($mediaId ?? '')).'" data-le-alt="'.e($altSuggest).'"');
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
