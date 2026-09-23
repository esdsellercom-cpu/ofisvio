<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kurulum ucunun hız sınırı (faz 62) — adlandırılmış `throttle:` limiter'ı KULLANILMAZ.
 *
 * Nedeni: RateLimiter tekil nesnesi boot anında (RateLimitServiceProvider) çözümlenir ve o andaki önbellek
 * sürücüsüne bağlanır. Taze pakette `CACHE_STORE=database` gelir, `cache` tablosu ise henüz yoktur — kurulum
 * ekranı daha açılmadan 500 verirdi. Bu middleware sayaçları doğrudan DOSYA önbelleğinde tutar.
 *
 * Anahtar `REMOTE_ADDR`'dan üretilir (proxy başlığı değil): TRUSTED_PROXIES kurulum sırasında `*` yazıldığında
 * X-Forwarded-For ile sayaç sıfırlanabilirdi.
 */
class ThrottleInstall
{
    /** Kurulum anında hazır olmayan önbellek sürücüleri. */
    private const INFRA_STORES = ['database', 'redis', 'memcached', 'dynamodb'];

    /** @var array<string, int> kova => dakikada izin verilen istek */
    private const LIMITS = ['token' => 5, 'step' => 40];

    public function handle(Request $request, Closure $next, string $bucket = 'step'): Response
    {
        $limit = self::LIMITS[$bucket] ?? self::LIMITS['step'];
        $key = 'install-throttle:'.$bucket.':'.sha1((string) $request->server('REMOTE_ADDR'));
        // Taze kurulumda önbellek sürücüsü `database`dir ve tablo yoktur → dosya deposu; zaten çalışan bir depo
        // varsa (dosya, array) o kullanılır.
        $store = in_array((string) config('cache.default'), self::INFRA_STORES, true) ? Cache::store('file') : Cache::store();

        $store->add($key, 0, 60);
        $hits = (int) $store->increment($key);

        abort_if($hits > $limit, 429, 'Çok fazla deneme. Bir dakika sonra yeniden deneyin.');

        return $next($request);
    }
}
