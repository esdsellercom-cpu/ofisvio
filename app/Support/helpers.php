<?php

use App\Support\Money;

if (! function_exists('money')) {
    /** Kuruş → biçimli tutar + simge (para birimi ayardan). Blade: {{ money($invoice->total) }} */
    function money(int|float|null $minor, ?string $currency = null): string
    {
        return Money::format($minor, $currency);
    }
}
