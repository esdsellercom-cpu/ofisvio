<?php

namespace App\Services;

use App\Integrations\Google\AnalyticsClient;
use App\Integrations\Google\SearchConsoleClient;
use App\Integrations\SecretStore;
use App\Models\IntegrationSyncState;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Arama performansı & analytics (faz 60d): Search Console ve GA4 verisi Gateway üzerinden çekilir, günlük özet
 * tablolarına yazılır; panel yalnız saklanan gerçek satırları gösterir. Bağlı değilse durum + adımlar; asla
 * sahte grafik/istatistik yok. Senkron komutla/zamanlayıcıyla; hata senkron durumuna yazılır (fail-closed).
 */
class SearchPerformanceService
{
    public function __construct(
        private readonly SecretStore $secrets,
        private readonly SeoSettingsService $settings,
        private readonly SearchConsoleClient $searchConsole,
        private readonly AnalyticsClient $analytics,
    ) {}

    /**
     * Bağlantı durumu: env + ayar + son senkron.
     *
     * @return array{enabled: bool, secrets_ok: bool, property: string, configured: bool, state: IntegrationSyncState|null, verified: bool|null, steps: list<string>}
     */
    public function status(Website $website, string $provider): array
    {
        $enabled = $this->secrets->enabled($provider);
        $secretsOk = $enabled && $this->secrets->missing($provider) === [];
        $property = $this->settings->string($website, $provider === 'search_console' ? 'integrations.gsc_property' : 'integrations.ga4_property');
        $state = IntegrationSyncState::query()->where('website_id', $website->id)->where('provider', $provider)->first();
        $verified = null;

        if ($provider === 'search_console' && $state !== null && is_array($state->meta['sites'] ?? null)) {
            $verified = false;

            foreach ($state->meta['sites'] as $site) {
                if (($site['siteUrl'] ?? '') === $property && ! in_array($site['permissionLevel'] ?? '', ['', 'siteUnverifiedUser'], true)) {
                    $verified = true;
                }
            }
        }

        $steps = [];

        if (! $enabled) {
            $steps[] = strtoupper($provider).'_ENABLED=true (env)';
        }
        if ($enabled && ! $secretsOk) {
            $steps[] = strtoupper($provider).'_SERVICE_ACCOUNT_JSON (servis hesabı JSON metni ya da dosya yolu)';
        }
        if ($property === '') {
            $steps[] = $provider === 'search_console' ? 'Search Console mülkü (SEO & GEO › Doğrulama & bildirim)' : 'GA4 mülk kimliği (SEO & GEO › Doğrulama & bildirim)';
        }
        if ($steps === [] && $state === null) {
            $steps[] = 'İlk senkron: php artisan ofisvio:'.($provider === 'search_console' ? 'search-console-sync' : 'analytics-sync');
        }

        return ['enabled' => $enabled, 'secrets_ok' => $secretsOk, 'property' => $property, 'configured' => $secretsOk && $property !== '', 'state' => $state, 'verified' => $verified, 'steps' => $steps];
    }

    /** Search Console senkronu: son N gün; boyut başına satırlar; mülk listesi + sitemap durumu meta'ya. */
    public function syncSearchConsole(Website $website, int $days = 28): int
    {
        $status = $this->status($website, 'search_console');
        $state = IntegrationSyncState::query()->firstOrNew(['website_id' => $website->id, 'provider' => 'search_console']);
        $state->last_attempt_at = now();

        if (! $status['configured']) {
            $state->last_error = 'Yapılandırma eksik: '.implode(' · ', $status['steps']);
            $state->save();

            return 0;
        }

        try {
            $end = CarbonImmutable::today()->subDays(2); // GSC verisi ~2 gün gecikmeli
            $start = $end->subDays($days - 1);
            $property = $status['property'];
            $written = 0;
            $sites = $this->searchConsole->sites();
            $sitemaps = $this->searchConsole->sitemaps($property);

            foreach (['total' => ['date'], 'query' => ['query'], 'page' => ['page'], 'country' => ['country'], 'device' => ['device']] as $dimension => $dims) {
                $rows = $this->searchConsole->query($property, $start->toDateString(), $end->toDateString(), $dims, $dimension === 'total' ? 100 : 250);

                DB::transaction(function () use ($website, $dimension, $rows, $start, $end, &$written) {
                    if ($dimension === 'total') {
                        DB::table('search_performance_daily')->where('website_id', $website->id)->where('dimension', 'total')->whereBetween('date', [$start->toDateString(), $end->toDateString()])->delete();

                        foreach ($rows as $row) {
                            DB::table('search_performance_daily')->insert(['website_id' => $website->id, 'date' => $row['keys'][0] ?? $end->toDateString(), 'dimension' => 'total', 'key' => '', 'clicks' => $row['clicks'], 'impressions' => $row['impressions'], 'ctr' => round($row['ctr'], 4), 'position' => round($row['position'], 2), 'created_at' => now(), 'updated_at' => now()]);
                            $written++;
                        }

                        return;
                    }

                    // Boyutlu satırlar dönemin toplamıdır; dönem sonu tarihiyle saklanır (aynı dönem yeniden yazılır).
                    DB::table('search_performance_daily')->where('website_id', $website->id)->where('dimension', $dimension)->where('date', $end->toDateString())->delete();

                    foreach ($rows as $row) {
                        DB::table('search_performance_daily')->insert(['website_id' => $website->id, 'date' => $end->toDateString(), 'dimension' => $dimension, 'key' => mb_substr($row['keys'][0] ?? '', 0, 500), 'clicks' => $row['clicks'], 'impressions' => $row['impressions'], 'ctr' => round($row['ctr'], 4), 'position' => round($row['position'], 2), 'created_at' => now(), 'updated_at' => now()]);
                        $written++;
                    }
                });
            }

            $state->meta = ['sites' => $sites, 'sitemaps' => $sitemaps, 'range' => [$start->toDateString(), $end->toDateString()], 'days' => $days];
            $state->last_success_at = now();
            $state->last_error = null;
            $state->save();

            return $written;
        } catch (Throwable $e) {
            $state->last_error = mb_substr($e->getMessage(), 0, 500);
            $state->save();

            throw $e;
        }
    }

    /** GA4 senkronu: organik oturum/etkileşim/dönüşüm — toplam (günlük), açılış sayfası, cihaz, kaynak. */
    public function syncAnalytics(Website $website, int $days = 28): int
    {
        $status = $this->status($website, 'analytics');
        $state = IntegrationSyncState::query()->firstOrNew(['website_id' => $website->id, 'provider' => 'analytics']);
        $state->last_attempt_at = now();

        if (! $status['configured']) {
            $state->last_error = 'Yapılandırma eksik: '.implode(' · ', $status['steps']);
            $state->save();

            return 0;
        }

        try {
            $end = CarbonImmutable::today()->subDay();
            $start = $end->subDays($days - 1);
            $property = $status['property'];
            $metrics = ['sessions', 'totalUsers', 'engagedSessions', 'keyEvents', 'engagementRate'];
            $written = 0;

            foreach (['total' => 'date', 'landing_page' => 'landingPagePlusQueryString', 'device' => 'deviceCategory', 'source' => 'sessionSource'] as $dimension => $gaDimension) {
                $rows = $this->analytics->report($property, $start->toDateString(), $end->toDateString(), [$gaDimension], $metrics);

                DB::transaction(function () use ($website, $dimension, $rows, $start, $end, &$written) {
                    if ($dimension === 'total') {
                        DB::table('analytics_daily')->where('website_id', $website->id)->where('dimension', 'total')->whereBetween('date', [$start->toDateString(), $end->toDateString()])->delete();
                    } else {
                        DB::table('analytics_daily')->where('website_id', $website->id)->where('dimension', $dimension)->where('date', $end->toDateString())->delete();
                    }

                    foreach ($rows as $row) {
                        $key = $row['dimensions'][0] ?? '';
                        $date = $dimension === 'total' && preg_match('/^\d{8}$/', $key) === 1 ? substr($key, 0, 4).'-'.substr($key, 4, 2).'-'.substr($key, 6, 2) : $end->toDateString();
                        DB::table('analytics_daily')->insert([
                            'website_id' => $website->id, 'date' => $date, 'dimension' => $dimension, 'key' => $dimension === 'total' ? '' : mb_substr(strtok($key, '?') ?: $key, 0, 500),
                            'sessions' => (int) ($row['metrics'][0] ?? 0), 'users' => (int) ($row['metrics'][1] ?? 0), 'engaged_sessions' => (int) ($row['metrics'][2] ?? 0), 'conversions' => (int) ($row['metrics'][3] ?? 0), 'engagement_rate' => round((float) ($row['metrics'][4] ?? 0), 4),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $written++;
                    }
                });
            }

            $state->meta = ['range' => [$start->toDateString(), $end->toDateString()], 'days' => $days];
            $state->last_success_at = now();
            $state->last_error = null;
            $state->save();

            return $written;
        } catch (Throwable $e) {
            $state->last_error = mb_substr($e->getMessage(), 0, 500);
            $state->save();

            throw $e;
        }
    }

    /**
     * Panel özeti (Search Console): toplamlar, günlük seri, boyut tabloları, içerik fırsatları.
     *
     * @return array<string, mixed>|null veri yoksa null
     */
    public function searchSummary(Website $website, int $days = 28): ?array
    {
        $rows = DB::table('search_performance_daily')->where('website_id', $website->id)->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $end = $rows->where('dimension', 'total')->max('date') ?? $rows->max('date');
        $start = CarbonImmutable::parse((string) $end)->subDays($days - 1)->toDateString();
        $daily = $rows->where('dimension', 'total')->filter(fn ($r) => $r->date >= $start)->sortBy('date')->values();
        $previousStart = CarbonImmutable::parse($start)->subDays($days)->toDateString();
        $previous = $rows->where('dimension', 'total')->filter(fn ($r) => $r->date >= $previousStart && $r->date < $start);
        $sum = fn ($set, string $col) => (int) $set->sum($col);
        $avgPosition = fn ($set) => $set->sum('impressions') > 0 ? round($set->sum(fn ($r) => $r->position * $r->impressions) / $set->sum('impressions'), 1) : null;
        $latest = $rows->whereIn('dimension', ['query', 'page', 'country', 'device'])->max('date');
        $dimension = fn (string $d) => $rows->where('dimension', $d)->where('date', $latest)->sortByDesc('clicks')->values()->all();
        $queries = $dimension('query');
        $pages = $dimension('page');

        // İçerik fırsatları: çok gösterim + 2. sayfa civarı sıra (8–20) → başlık/içerik iyileştirmesiyle tıklama kazanılır.
        $opportunities = array_values(array_filter($queries, fn ($q) => $q->impressions >= 50 && $q->position >= 8 && $q->position <= 20));
        usort($opportunities, fn ($a, $b) => $b->impressions <=> $a->impressions);

        return [
            'range' => [$start, $end],
            'totals' => ['clicks' => $sum($daily, 'clicks'), 'impressions' => $sum($daily, 'impressions'), 'ctr' => $sum($daily, 'impressions') > 0 ? round($sum($daily, 'clicks') / $sum($daily, 'impressions') * 100, 2) : 0, 'position' => $avgPosition($daily)],
            'previous' => ['clicks' => $sum($previous, 'clicks'), 'impressions' => $sum($previous, 'impressions'), 'position' => $avgPosition($previous)],
            'daily' => $daily->all(),
            'queries' => array_slice($queries, 0, 50),
            'pages' => array_slice($pages, 0, 50),
            'countries' => array_slice($dimension('country'), 0, 10),
            'devices' => $dimension('device'),
            'opportunities' => array_slice($opportunities, 0, 20),
            'latest' => $latest,
        ];
    }

    /**
     * Panel özeti (Analytics): organik oturumlar, açılış sayfaları (hizmet/lokasyon/blog dönüşümü), huni.
     *
     * @return array<string, mixed>|null
     */
    public function analyticsSummary(Website $website, int $days = 28): ?array
    {
        $rows = DB::table('analytics_daily')->where('website_id', $website->id)->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $end = $rows->where('dimension', 'total')->max('date') ?? $rows->max('date');
        $start = CarbonImmutable::parse((string) $end)->subDays($days - 1)->toDateString();
        $daily = $rows->where('dimension', 'total')->filter(fn ($r) => $r->date >= $start)->sortBy('date')->values();
        $latest = $rows->whereIn('dimension', ['landing_page', 'device', 'source'])->max('date');
        $landing = $rows->where('dimension', 'landing_page')->where('date', $latest)->sortByDesc('sessions')->values()->all();
        $kind = fn (string $path) => match (true) {
            str_starts_with($path, '/cozum/') => 'service',
            str_starts_with($path, '/lokasyon/') => 'location',
            str_starts_with($path, '/blog/') => 'blog',
            $path === '/' => 'home',
            preg_match('#^/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1 => 'landing',
            default => 'page',
        };
        $byKind = [];

        foreach ($landing as $row) {
            $k = $kind((string) $row->key);
            $byKind[$k] ??= ['sessions' => 0, 'engaged' => 0, 'conversions' => 0, 'pages' => 0];
            $byKind[$k]['sessions'] += $row->sessions;
            $byKind[$k]['engaged'] += $row->engaged_sessions;
            $byKind[$k]['conversions'] += $row->conversions;
            $byKind[$k]['pages']++;
        }

        $sessions = (int) $daily->sum('sessions');
        $engaged = (int) $daily->sum('engaged_sessions');
        $conversions = (int) $daily->sum('conversions');

        return [
            'range' => [$start, $end],
            'totals' => ['sessions' => $sessions, 'users' => (int) $daily->sum('users'), 'engaged' => $engaged, 'conversions' => $conversions, 'engagement_rate' => $sessions > 0 ? round($engaged / $sessions * 100, 1) : 0, 'conversion_rate' => $sessions > 0 ? round($conversions / $sessions * 100, 2) : 0],
            'daily' => $daily->all(),
            'landing' => array_slice($landing, 0, 50),
            'by_kind' => $byKind,
            'devices' => $rows->where('dimension', 'device')->where('date', $latest)->sortByDesc('sessions')->values()->all(),
            'sources' => $rows->where('dimension', 'source')->where('date', $latest)->sortByDesc('sessions')->take(10)->values()->all(),
            'funnel' => ['sessions' => $sessions, 'engaged' => $engaged, 'conversions' => $conversions],
            'latest' => $latest,
        ];
    }

    /**
     * Sayfa bazlı tıklama değişimi (içerik yenileme adayları için): son dönem vs önceki dönem (yalnız veri varsa).
     *
     * @return array<string, array{now: int, before: int}> yol => tıklamalar
     */
    public function pageClickTrend(Website $website): array
    {
        $rows = DB::table('search_performance_daily')->where('website_id', $website->id)->where('dimension', 'page')->orderBy('date')->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $dates = $rows->pluck('date')->unique()->sort()->values();

        if ($dates->count() < 2) {
            return [];
        }

        $now = $rows->where('date', $dates->last());
        $before = $rows->where('date', $dates->get($dates->count() - 2));
        $base = $website->baseUrl();
        $out = [];

        foreach ($now as $row) {
            $path = (string) (parse_url((string) $row->key, PHP_URL_PATH) ?: '/');
            $out[$path] = ['now' => (int) $row->clicks, 'before' => (int) ($before->firstWhere('key', $row->key)->clicks ?? 0)];
        }

        unset($base);

        return $out;
    }
}
