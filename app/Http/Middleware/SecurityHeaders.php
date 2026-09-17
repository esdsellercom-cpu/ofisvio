<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Güvenlik başlıkları (audit S-1, P0). Her web yanıtına: CSP, X-Frame-Options,
 * X-Content-Type-Options, Referrer-Policy, Permissions-Policy; HTTPS'te HSTS.
 *
 * CSP kaynak listesi config/ofisvio.php › security'den (Google Fonts, OpenStreetMap
 * gömme). Satır içi stil/olay işleyicileri (style="…", onchange="…") tasarımda
 * yaygın; bu yüzden 'unsafe-inline' kalır — dış (enjekte) betik ve iframe'ler
 * yine engellenir. Panel hiçbir yerde çerçevelenemez (frame-ancestors 'none');
 * vitrin yalnız kendi origin'inde (sayfa kurucu önizleme iframe'i).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $panel = $request->is('panel', 'panel/*', 'login', 'two-factor-challenge', 'user/*', 'forgot-password', 'reset-password/*');
        $cfg = (array) config('ofisvio.security');

        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline' ".implode(' ', (array) ($cfg['style_src'] ?? [])),
            "font-src 'self' data: ".implode(' ', (array) ($cfg['font_src'] ?? [])),
            "img-src 'self' data: https:",
            "connect-src 'self'",
            "frame-src 'self' ".implode(' ', (array) ($cfg['frame_src'] ?? [])),
            'frame-ancestors '.($panel ? "'none'" : "'self'"),
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ];

        $response->headers->set('Content-Security-Policy', trim(implode('; ', $csp)));
        $response->headers->set('X-Frame-Options', $panel ? 'DENY' : 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        if ($request->isSecure() && (bool) ($cfg['hsts'] ?? true)) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
