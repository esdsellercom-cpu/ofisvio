<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\AuthorizationService;
use App\Services\ContentService;
use App\Services\SearchPerformanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Search Console + Analytics ekranları (faz 60d): seo.analytics.view görür; senkron tetikleme
 * seo.audit (yalnız okuma senkronu; API anahtarı yönetimi değil). Veri yoksa yalnız bağlantı durumu ve adımlar — sahte grafik yok.
 */
class SearchPerformanceController extends Controller
{
    public function __construct(private readonly SearchPerformanceService $performance, private readonly ContentService $contents, private readonly AuthorizationService $authorization) {}

    public function searchConsoleHome(): RedirectResponse
    {
        return redirect()->route('panel.seo.search-console', $this->contents->defaultWebsite());
    }

    public function searchConsole(Request $request, Website $website): View
    {
        $days = self::days($request);

        return view('panel.seo.search-console', [
            'website' => $website,
            'days' => $days,
            'status' => $this->performance->status($website, 'search_console'),
            'summary' => $this->performance->searchSummary($website, $days),
            'canSync' => $this->authorization->can($request->user(), 'seo.audit'),
        ]);
    }

    public function analyticsHome(): RedirectResponse
    {
        return redirect()->route('panel.seo.analytics', $this->contents->defaultWebsite());
    }

    public function analytics(Request $request, Website $website): View
    {
        $days = self::days($request);

        return view('panel.seo.analytics', [
            'website' => $website,
            'days' => $days,
            'status' => $this->performance->status($website, 'analytics'),
            'summary' => $this->performance->analyticsSummary($website, $days),
            'canSync' => $this->authorization->can($request->user(), 'seo.audit'),
        ]);
    }

    /** Elle senkron (seo.audit, salt okuma): sağlayıcı bağlı değilse hata mesajı, veri uydurulmaz. */
    public function sync(Request $request, Website $website, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, ['search_console', 'analytics'], true), 404);

        try {
            $written = $provider === 'search_console' ? $this->performance->syncSearchConsole($website) : $this->performance->syncAnalytics($website);
        } catch (Throwable $e) {
            return back()->withErrors(['sync' => 'Senkron başarısız: '.mb_substr($e->getMessage(), 0, 300)]);
        }

        return back()->with('status', $written > 0 ? $written.' satır alındı.' : 'Sağlayıcı yapılandırılmamış; durum kaydedildi.');
    }

    private static function days(Request $request): int
    {
        $days = (int) $request->query('gun', 28);

        return in_array($days, [7, 28, 90], true) ? $days : 28;
    }
}
