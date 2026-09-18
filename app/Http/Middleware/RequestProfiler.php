<?php

namespace App\Http\Middleware;

use App\Models\PerformanceSample;
use App\Models\SlowQuery;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * İstek profili (faz 60f, Performance Command Center): her web isteğinde sorgu sayısı/süresi sayılır; istek
 * örneklenirse (config ofisvio.performance.sample_rate) TTFB/bellek/yanıt boyutu/önbellek isabeti yazılır; eşiği
 * (slow_query_ms) aşan sorgular bağlamsız SQL ile kaydedilir. Ziyaretçi için maliyet: sayaç + nadiren tek insert;
 * kayıt yanıt üretildikten sonra (terminate) yapılır, hata olursa sessizce atlanır — ölçüm asla isteği bozmaz.
 * Dinleyici süreç başına bir kez bağlanır (test/Octane'da birikmez). Kişisel veri: yol sorgu dizgisiz, SQL bağlamsız.
 */
class RequestProfiler
{
    private static int $queries = 0;

    private static float $queryMs = 0.0;

    /** @var list<array{sql: string, ms: float, connection: string}> */
    private static array $slow = [];

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('ofisvio.performance.enabled', true)) {
            return $next($request);
        }

        self::$queries = 0;
        self::$queryMs = 0.0;
        self::$slow = [];

        // Dinleyici uygulama örneği başına bir kez (testte her test yeni uygulama kurar; eski dinleyici eski bağlantıda kalır).
        if (! app()->bound('ofisvio.profiler.listening')) {
            app()->instance('ofisvio.profiler.listening', true);
            DB::listen(function (QueryExecuted $event): void {
                self::$queries++;
                self::$queryMs += $event->time;

                if ($event->time >= (float) config('ofisvio.performance.slow_query_ms', 100) && count(self::$slow) < 20) {
                    self::$slow[] = ['sql' => $event->sql, 'ms' => $event->time, 'connection' => (string) $event->connectionName];
                }
            });
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! (bool) config('ofisvio.performance.enabled', true)) {
            return;
        }

        $queries = self::$queries;
        $queryMs = self::$queryMs;
        $slow = self::$slow;
        self::$slow = [];

        try {
            $route = $request->route()?->getName();
            $path = '/'.trim($request->path(), '/');
            $kind = str_starts_with($path, '/panel') ? 'panel' : (in_array($path, ['/up', '/login', '/logout'], true) || str_starts_with($path, '/webhooks') ? 'other' : 'site');
            $now = now();

            foreach ($slow as $entry) {
                SlowQuery::query()->create([
                    'route' => $route !== null ? mb_substr($route, 0, 120) : null,
                    'sql_hash' => sha1($entry['sql']),
                    'sql' => mb_substr($entry['sql'], 0, 4000),
                    'duration_ms' => (int) round($entry['ms']),
                    'connection' => mb_substr($entry['connection'], 0, 32),
                    'created_at' => $now,
                ]);
            }

            $rate = (float) config('ofisvio.performance.sample_rate', 0.05);

            if ($rate <= 0 || ($rate < 1 && mt_rand() / mt_getrandmax() > $rate)) {
                return;
            }

            $content = $response->getContent();
            $started = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));
            PerformanceSample::query()->create([
                'kind' => $kind,
                'route' => $route !== null ? mb_substr($route, 0, 120) : null,
                'path' => mb_substr($path, 0, 160),
                'method' => mb_substr($request->method(), 0, 8),
                'status' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'query_count' => $queries,
                'query_ms' => (int) round($queryMs),
                'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'response_bytes' => is_string($content) ? strlen($content) : 0,
                'cache_hit' => $response->getStatusCode() === 304,
                'authenticated' => $request->user() !== null,
                'created_at' => $now,
            ]);
        } catch (Throwable) {
            // Ölçüm isteği asla bozmaz.
        }
    }
}
