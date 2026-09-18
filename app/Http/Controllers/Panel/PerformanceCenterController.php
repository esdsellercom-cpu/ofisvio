<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PublicCacheHeaders;
use App\Services\AuthorizationService;
use App\Services\ContentCache;
use App\Services\ContentService;
use App\Services\PerformanceCenterService;
use App\Services\WebVitalsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Performance Command Center (faz 60f): performance.view görür; ölçüm tetikleme (Core Web Vitals) performance.audit.
 * Tüm rakamlar gerçek ölçümlerden (istek örnekleri, yavaş sorgular, ping, PageSpeed); veri yoksa "veri yok".
 */
class PerformanceCenterController extends Controller
{
    public function __construct(
        private readonly PerformanceCenterService $performance,
        private readonly WebVitalsService $vitals,
        private readonly ContentService $contents,
        private readonly ContentCache $cache,
        private readonly AuthorizationService $authorization,
    ) {}

    public function dashboard(Request $request): View
    {
        $hours = in_array((int) $request->query('saat', 24), [1, 24, 168], true) ? (int) $request->query('saat', 24) : 24;
        $website = $this->contents->defaultWebsiteOrNull();

        return view('panel.performance.center', [
            'overview' => $this->performance->overview($hours),
            'hours' => $hours,
            'cacheStats' => $website !== null ? $this->cache->stats($website) : null,
            'cacheRoundTrip' => $this->performance->cacheRoundTrip(),
            'vitals' => $website !== null ? $this->vitals->latest($website) : collect(),
            'events' => $this->performance->cacheEvents(null, 8),
        ]);
    }

    public function queries(Request $request): View
    {
        return view('panel.performance.queries', ['routes' => $this->performance->routes(), 'config' => config('ofisvio.performance')]);
    }

    public function slowQueries(): View
    {
        return view('panel.performance.slow-queries', ['queries' => $this->performance->slowQueries(), 'threshold' => (int) config('ofisvio.performance.slow_query_ms')]);
    }

    public function redis(): View
    {
        return view('panel.performance.redis', ['info' => $this->performance->redisInfo(), 'latency' => $this->performance->latency()]);
    }

    public function httpCache(): View
    {
        $websites = $this->contents->allWebsites()->map(fn ($site) => ['website' => $site, 'stats' => $this->cache->stats($site), 'ttl' => $this->cache->ttl($site), 'max_age' => (int) ($site->http_max_age ?? PublicCacheHeaders::MAX_AGE), 's_maxage' => (int) ($site->http_s_maxage ?? PublicCacheHeaders::S_MAXAGE)]);

        return view('panel.performance.http-cache', ['websites' => $websites, 'events' => $this->performance->cacheEvents(null, 30)]);
    }

    public function assets(): View
    {
        return view('panel.performance.assets', ['assets' => $this->performance->assets()]);
    }

    public function vitals(Request $request): View
    {
        $website = $this->contents->defaultWebsite();

        return view('panel.performance.vitals', [
            'website' => $website,
            'rows' => $this->vitals->latest($website),
            'state' => $this->vitals->state($website),
            'thresholds' => WebVitalsService::THRESHOLDS,
            'paths' => $this->vitals->representativePaths($website),
            'enabled' => (bool) config('integrations.providers.pagespeed.enabled'),
            'canMeasure' => $this->authorization->can($request->user(), 'performance.audit'),
        ]);
    }

    /** Ölçüm (performance.audit): PageSpeed sağlayıcısı kapalıysa mesaj, veri uydurulmaz. */
    public function measureVitals(): RedirectResponse
    {
        $result = $this->vitals->measure($this->contents->defaultWebsite());

        if ($result['measured'] === 0) {
            return back()->withErrors(['vitals' => implode(' · ', $result['errors']) ?: 'Ölçüm yapılamadı.']);
        }

        return back()->with('status', $result['measured'].' ölçüm kaydedildi.'.($result['errors'] !== [] ? ' Hatalar: '.implode(' · ', $result['errors']) : ''));
    }

    public function audit(): View
    {
        return view('panel.performance.audit', ['rows' => $this->performance->audit(), 'latency' => $this->performance->latency(), 'queue' => $this->performance->queue()]);
    }
}
