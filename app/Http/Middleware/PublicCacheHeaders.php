<?php

namespace App\Http\Middleware;

use App\Services\CurrentWebsite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vitrin için HTTP önbellek başlıkları (faz 12).
 *
 *   Misafir + GET + 200 : public, max-age=60, s-maxage=300, stale-while-revalidate=300
 *                         + ETag (If-None-Match eşleşirse 304, gövdesiz)
 *   Oturum açık          : private, no-store — "Panel" bağlantısı gibi kişiye özel
 *                         parçalar CDN/proxy'de asla paylaşılmaz.
 *   Diğer (POST, 4xx/5xx): dokunulmaz.
 *
 * Süreler kısa tutulur: CMS yayını uygulama önbelleğini anında geçersiz kılar
 * ama proxy kopyası en fazla s-maxage kadar bayat kalabilir.
 */
class PublicCacheHeaders
{
    public const MAX_AGE = 60;

    public const S_MAXAGE = 300;

    public function __construct(private readonly CurrentWebsite $website) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $response;
        }

        if ($request->user() !== null) {
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        // Süreler site başına ayarlanabilir (cache.settings, JIT); NULL = varsayılan.
        $site = $this->website->get();

        $response->setCache([
            'public' => true,
            'max_age' => (int) ($site->http_max_age ?? self::MAX_AGE),
            's_maxage' => (int) ($site->http_s_maxage ?? self::S_MAXAGE),
            'stale_while_revalidate' => 300,
            'etag' => '"'.sha1((string) $response->getContent()).'"',
        ]);

        // If-None-Match eşleşirse 304 — bant genişliği ve render tasarrufu.
        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }
}
