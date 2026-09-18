<?php

namespace App\Services;

use App\Models\CacheEvent;
use App\Models\PerformanceSample;
use App\Models\SlowQuery;
use App\Models\Website;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Performance Command Center (faz 60f): gerçek ölçümlerden özet — istek örnekleri (TTFB p50/p95, sorgu sayısı,
 * yanıt boyutu, önbellek isabeti), yavaş sorgular, DB/Redis/kuyruk gecikmesi (şimdi ölçülür), varlık (asset)
 * boyutları, performans denetimi (config/route/view cache, opcache, debug, sürücüler). Ölçüm yoksa "veri yok";
 * tahmini rakam üretilmez.
 */
class PerformanceCenterService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(int $hours = 24): array
    {
        $since = now()->subHours($hours);
        $rows = PerformanceSample::query()->where('created_at', '>=', $since)->get();
        $byKind = [];

        foreach (['site', 'panel'] as $kind) {
            $set = $rows->where('kind', $kind);
            $durations = $set->pluck('duration_ms')->sort()->values();
            $byKind[$kind] = [
                'count' => $set->count(),
                'p50' => self::percentile($durations, 50),
                'p95' => self::percentile($durations, 95),
                'queries_avg' => $set->count() > 0 ? round($set->avg('query_count'), 1) : null,
                'query_ms_avg' => $set->count() > 0 ? round($set->avg('query_ms'), 1) : null,
                'bytes_avg' => $set->count() > 0 ? (int) round($set->avg('response_bytes')) : null,
                'cache_hit_ratio' => $set->count() > 0 ? round($set->where('cache_hit', true)->count() / $set->count() * 100, 1) : null,
                'memory_avg' => $set->count() > 0 ? round($set->avg('memory_mb'), 1) : null,
            ];
        }

        return [
            'hours' => $hours,
            'samples' => $rows->count(),
            'by_kind' => $byKind,
            'slow_queries' => SlowQuery::query()->where('created_at', '>=', $since)->count(),
            'latency' => $this->latency(),
            'queue' => $this->queue(),
            'config' => ['sample_rate' => (float) config('ofisvio.performance.sample_rate'), 'slow_query_ms' => (int) config('ofisvio.performance.slow_query_ms'), 'enabled' => (bool) config('ofisvio.performance.enabled')],
        ];
    }

    /**
     * Rota bazında sorgu performansı (son N saat): ortalama sorgu, p95 süre, örnek sayısı — sıralı.
     *
     * @return list<array{route: string, kind: string, count: int, queries_avg: float, query_ms_avg: float, p95: int|null, bytes_avg: int}>
     */
    public function routes(int $hours = 168): array
    {
        $rows = PerformanceSample::query()->where('created_at', '>=', now()->subHours($hours))->get()->groupBy(fn (PerformanceSample $s) => $s->route ?? $s->path);
        $out = [];

        foreach ($rows as $route => $set) {
            $out[] = [
                'route' => (string) $route,
                'kind' => (string) $set->first()->kind,
                'count' => $set->count(),
                'queries_avg' => round($set->avg('query_count'), 1),
                'query_ms_avg' => round($set->avg('query_ms'), 1),
                'p95' => self::percentile($set->pluck('duration_ms')->sort()->values(), 95),
                'bytes_avg' => (int) round($set->avg('response_bytes')),
            ];
        }

        usort($out, fn (array $a, array $b) => $b['queries_avg'] <=> $a['queries_avg']);

        return $out;
    }

    /**
     * Yavaş sorgular: aynı SQL gruplanır (adet, ort/maks süre, son görülme, rotalar).
     *
     * @return list<array{sql: string, hash: string, count: int, avg_ms: float, max_ms: int, last_at: string, routes: list<string>}>
     */
    public function slowQueries(int $hours = 168): array
    {
        $rows = SlowQuery::query()->where('created_at', '>=', now()->subHours($hours))->orderByDesc('created_at')->limit(2000)->get()->groupBy('sql_hash');
        $out = [];

        foreach ($rows as $hash => $set) {
            $out[] = [
                'sql' => (string) $set->first()->sql,
                'hash' => (string) $hash,
                'count' => $set->count(),
                'avg_ms' => round($set->avg('duration_ms'), 1),
                'max_ms' => (int) $set->max('duration_ms'),
                'last_at' => (string) $set->first()->created_at,
                'routes' => $set->pluck('route')->filter()->unique()->values()->all(),
            ];
        }

        usort($out, fn (array $a, array $b) => $b['count'] * $b['avg_ms'] <=> $a['count'] * $a['avg_ms']);

        return $out;
    }

    /**
     * DB / Redis gecikmesi şimdi ölçülür (tek hafif sorgu / ping).
     *
     * @return array{db_ms: float|null, db_driver: string, redis_ms: float|null, redis: string, cache_store: string}
     */
    public function latency(): array
    {
        $dbMs = null;

        try {
            $t = hrtime(true);
            DB::select('select 1');
            $dbMs = round((hrtime(true) - $t) / 1e6, 2);
        } catch (Throwable) {
        }

        $redisMs = null;
        $redisState = 'yapılandırılmamış';
        $store = (string) config('cache.default');

        if ($store === 'redis' || (string) config('session.driver') === 'redis' || (string) config('queue.default') === 'redis') {
            try {
                $t = hrtime(true);
                Redis::connection()->ping();
                $redisMs = round((hrtime(true) - $t) / 1e6, 2);
                $redisState = 'bağlı';
            } catch (Throwable $e) {
                $redisState = 'erişilemiyor: '.mb_substr($e->getMessage(), 0, 80);
            }
        }

        return ['db_ms' => $dbMs, 'db_driver' => (string) config('database.default'), 'redis_ms' => $redisMs, 'redis' => $redisState, 'cache_store' => $store];
    }

    /**
     * Kuyruk gecikmesi: bekleyen iş sayısı, en eski bekleyen işin yaşı, başarısız iş sayısı (database sürücüsü).
     *
     * @return array{driver: string, pending: int|null, oldest_seconds: int|null, failed: int|null}
     */
    public function queue(): array
    {
        $driver = (string) config('queue.default');
        $pending = null;
        $oldest = null;
        $failed = null;

        try {
            if ($driver === 'database') {
                $pending = (int) DB::table('jobs')->count();
                $oldestAt = DB::table('jobs')->min('available_at');
                $oldest = $oldestAt !== null ? max(0, now()->timestamp - (int) $oldestAt) : null;
            }

            $failed = (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
        }

        return ['driver' => $driver, 'pending' => $pending, 'oldest_seconds' => $oldest, 'failed' => $failed];
    }

    /**
     * Redis bilgisi (yalnız redis sürücüsünde): INFO alt kümesi — bellek, anahtar sayısı, isabet oranı, uptime.
     *
     * @return array<string, string|int|float|null>|null
     */
    public function redisInfo(): ?array
    {
        if ((string) config('cache.default') !== 'redis' && (string) config('session.driver') !== 'redis' && (string) config('queue.default') !== 'redis') {
            return null;
        }

        try {
            $info = Redis::connection()->command('info');
            $info = is_array($info) ? $info : [];
            $flat = [];

            foreach ($info as $k => $v) {
                if (is_array($v)) {
                    $flat = array_merge($flat, $v);
                } else {
                    $flat[$k] = $v;
                }
            }

            $hits = (int) ($flat['keyspace_hits'] ?? 0);
            $misses = (int) ($flat['keyspace_misses'] ?? 0);

            return [
                'version' => (string) ($flat['redis_version'] ?? '—'),
                'used_memory_human' => (string) ($flat['used_memory_human'] ?? '—'),
                'maxmemory_human' => (string) ($flat['maxmemory_human'] ?? '—'),
                'connected_clients' => (int) ($flat['connected_clients'] ?? 0),
                'uptime_days' => (int) ($flat['uptime_in_days'] ?? 0),
                'keyspace_hits' => $hits,
                'keyspace_misses' => $misses,
                'hit_ratio' => $hits + $misses > 0 ? round($hits / ($hits + $misses) * 100, 1) : null,
                'evicted_keys' => (int) ($flat['evicted_keys'] ?? 0),
                'keys' => (int) preg_replace('/^keys=(\d+).*$/', '$1', (string) ($flat['db0'] ?? 'keys=0')),
            ];
        } catch (Throwable $e) {
            return ['error' => mb_substr($e->getMessage(), 0, 120)];
        }
    }

    /**
     * Varlık (asset) boyutları: public/css, public/js ve Vite build çıktısı; büyük dosyalar işaretlenir.
     *
     * @return list<array{file: string, bytes: int, gzip_bytes: int|null, note: string|null}>
     */
    public function assets(): array
    {
        $out = [];

        foreach (['css' => public_path('css'), 'js' => public_path('js'), 'build' => public_path('build/assets')] as $group => $dir) {
            if (! File::isDirectory($dir)) {
                continue;
            }

            foreach (File::files($dir) as $file) {
                if (! in_array($file->getExtension(), ['css', 'js'], true)) {
                    continue;
                }

                $bytes = (int) $file->getSize();
                $gzip = function_exists('gzencode') ? strlen((string) gzencode((string) File::get($file->getPathname()), 6)) : null;
                $out[] = ['file' => $group.'/'.$file->getFilename(), 'bytes' => $bytes, 'gzip_bytes' => $gzip, 'note' => $bytes > 200_000 ? 'büyük — bölmeyi/sadeleştirmeyi değerlendirin' : null];
            }
        }

        usort($out, fn (array $a, array $b) => $b['bytes'] <=> $a['bytes']);

        return $out;
    }

    /**
     * Performans denetimi: uygulama katmanı kontrol listesi (gerçek durumdan).
     *
     * @return list<array{name: string, level: string, note: string}>
     */
    public function audit(): array
    {
        $production = (string) config('app.env') === 'production';
        $rows = [];
        $check = function (string $name, bool $ok, string $okNote, string $failNote, bool $strict = true) use (&$rows, $production): void {
            $rows[] = ['name' => $name, 'level' => $ok ? 'ok' : ($strict && $production ? 'fail' : 'warn'), 'note' => $ok ? $okNote : $failNote];
        };

        $check('Config önbelleği', File::exists(base_path('bootstrap/cache/config.php')), 'config:cache alınmış', 'php artisan config:cache (üretimde her istek 40+ config dosyası okur)');
        $check('Rota önbelleği', File::exists(base_path('bootstrap/cache/routes-v7.php')), 'route:cache alınmış', 'php artisan route:cache');
        $check('Görünüm önbelleği', count(File::glob(storage_path('framework/views/*.php')) ?: []) > 0, 'derlenmiş görünümler var', 'php artisan view:cache');
        $check('OPcache', function_exists('opcache_get_status') && (bool) ini_get('opcache.enable'), 'açık', 'opcache.enable=1 (php.ini) — PHP derleme maliyeti her istekte', true);
        $check('APP_DEBUG', ! (bool) config('app.debug'), 'kapalı', 'üretimde kapalı olmalı (yavaş + bilgi sızıntısı)');
        $check('Önbellek sürücüsü', in_array((string) config('cache.default'), ['redis', 'memcached', 'dynamodb', 'database'], true), (string) config('cache.default'), 'file/array sürücüsü üretim için uygun değil');
        $check('Redis (önbellek)', (string) config('cache.default') === 'redis', 'redis', 'Redis yok — database sürücüsü çalışır ama her remember() DB\'ye gider', false);
        $check('Kuyruk sürücüsü', (string) config('queue.default') !== 'sync', (string) config('queue.default'), 'sync: bildirim/IndexNow işleri isteği bekletir');
        $check('Oturum sürücüsü', in_array((string) config('session.driver'), ['redis', 'database', 'memcached'], true), (string) config('session.driver'), 'file oturumu çok sunucuda çalışmaz', false);
        $check('Vitrin HTTP önbelleği', true, 'misafire public, max-age + ETag/304; oturum açana no-store (PublicCacheHeaders)', '');
        $check('İstek profili', (bool) config('ofisvio.performance.enabled'), 'açık — örnekleme %'.((float) config('ofisvio.performance.sample_rate') * 100), 'kapalı (PERF_PROFILER)', false);
        $check('Asset sürümleme', true, 'asset_v() sorgu sürümü + uzun max-age uygun', '');

        return $rows;
    }

    /**
     * Son önbellek olayları (kaskad izi).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CacheEvent>
     */
    public function cacheEvents(?Website $website = null, int $limit = 30): \Illuminate\Database\Eloquent\Collection
    {
        return CacheEvent::query()->when($website !== null, fn ($q) => $q->where('website_id', $website->id))->orderByDesc('id')->limit($limit)->get();
    }

    /** Uygulama önbelleği hızlı sağlık: yazma/okuma süresi. */
    public function cacheRoundTrip(): ?float
    {
        try {
            $key = 'perf:probe:'.bin2hex(random_bytes(4));
            $t = hrtime(true);
            Cache::put($key, 1, 10);
            Cache::get($key);
            Cache::forget($key);

            return round((hrtime(true) - $t) / 1e6, 2);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  Collection<int, int>  $sorted */
    private static function percentile($sorted, int $p): ?int
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        $index = (int) ceil($p / 100 * $sorted->count()) - 1;

        return (int) $sorted->get(max(0, min($sorted->count() - 1, $index)));
    }
}
