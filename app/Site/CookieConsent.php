<?php

namespace App\Site;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Çerez rızası (audit F-06, KVKK/ePrivacy). Tercih sunucu tarafı çerezdedir (tarayıcı depolaması yok):
 * `all` = analitik/3. taraf etiketlere izin, `essential` = yalnız zorunlu çerezler (oturum, CSRF).
 * GA4/GTM yalnız `all` iken basılır; tercih yoksa ve sitede 3. taraf etiket tanımlıysa rıza bandı gösterilir.
 * Rıza zorunlu çerezleri etkilemez; tercih HttpOnly değildir (JS'e gerek yok, yine de değer gizli değil).
 */
final class CookieConsent
{
    public const COOKIE = 'ofv_consent';

    public const ALL = 'all';

    public const ESSENTIAL = 'essential';

    public const DAYS = 180;

    /** Geçerli tercih ya da null (henüz seçilmedi). */
    public static function fromRequest(Request $request): ?string
    {
        $value = (string) $request->cookie(self::COOKIE, '');

        return in_array($value, [self::ALL, self::ESSENTIAL], true) ? $value : null;
    }

    public static function make(string $choice, bool $secure): Cookie
    {
        return Cookie::create(self::COOKIE, $choice === self::ALL ? self::ALL : self::ESSENTIAL)
            ->withExpires(time() + self::DAYS * 86400)
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    /** Sitede rıza gerektiren 3. taraf etiket var mı (GA4 / GTM)? @param  array<string, mixed>  $seo */
    public static function needsBanner(array $seo): bool
    {
        return (string) ($seo['ga4_id'] ?? '') !== '' || (string) ($seo['gtm_id'] ?? '') !== '';
    }
}
