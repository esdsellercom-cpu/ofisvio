<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Ters proxy güveni (audit F-12). TRUSTED_PROXIES boşsa hiçbir proxy'ye güvenilmez (varsayılan, fail-closed):
 * X-Forwarded-* başlıkları yok sayılır → nginx/CDN arkasında `isSecure()` false kalır, HSTS basılmaz, `secure` çerez
 * ve imzalı URL şeması yanlış olur. Üretimde proxy IP'lerini (virgülle) ya da tek proxy katmanı için `*` verin.
 * Config'ten okunur (env() değil): config:cache ile de çalışır.
 */
class TrustProxies extends Middleware
{
    /** @return array<int, string>|string|null */
    protected function proxies(): array|string|null
    {
        $value = trim((string) config('ofisvio.security.trusted_proxies', ''));

        if ($value === '') {
            return null;
        }

        if ($value === '*' || $value === '**') {
            return $value;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
