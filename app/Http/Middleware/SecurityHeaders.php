<?php

namespace App\Http\Middleware;

use App\Services\CurrentWebsite;
use App\Services\SeoSettingsService;
use App\Support\Csp;
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
    /** GA4/GTM kökenleri (faz 44): yalnız vitrinde ve yalnız panelde kimlik tanımlıysa CSP'ye eklenir. */
    private const ANALYTICS_ORIGINS = ['https://www.googletagmanager.com', 'https://www.google-analytics.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com'];

    public function __construct(private readonly CurrentWebsite $website, private readonly SeoSettingsService $seoSettings) {}

    public function handle(Request $request, Closure $next): Response
    {
        Csp::reset();
        $nonce = Csp::nonce(); // görünümler render edilmeden önce üretilir; yanıt başlığıyla aynı değer
        $response = $next($request);
        $panel = $request->is('panel', 'panel/*', 'login', 'two-factor-challenge', 'user/*', 'forgot-password', 'reset-password/*');
        $cfg = (array) config('ofisvio.security');
        $analytics = ! $panel && $this->analyticsEnabled() ? ' '.implode(' ', self::ANALYTICS_ORIGINS) : '';

        $csp = [
            "default-src 'self'",
            // audit F-11: satır içi script yalnız nonce ile; 'strict-dynamic' nonce'lu scriptin yüklediklerine (GTM) izin verir, 'self' + host listesi eski tarayıcılar için kalır.
            "script-src 'self' 'nonce-".$nonce."' 'strict-dynamic'".$analytics,
            "style-src 'self' 'unsafe-inline' ".implode(' ', (array) ($cfg['style_src'] ?? [])),
            "font-src 'self' data: ".implode(' ', (array) ($cfg['font_src'] ?? [])),
            "img-src 'self' data: https:",
            "connect-src 'self'".$analytics,
            "frame-src 'self' ".implode(' ', (array) ($cfg['frame_src'] ?? [])).($analytics !== '' ? ' https://www.googletagmanager.com' : ''),
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

    private function analyticsEnabled(): bool
    {
        $site = $this->website->get();

        return $site !== null && ($this->seoSettings->string($site, 'verify.ga4_id') !== '' || $this->seoSettings->string($site, 'verify.gtm_id') !== '');
    }
}
