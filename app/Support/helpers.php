<?php

use App\Support\Money;

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
