<?php

namespace App\Http\Controllers\Panel;

use App\Console\Commands\DoctorCommand;
use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\AuthorizationService;
use App\Services\ContentCache;
use App\Services\ContentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Performance Command Center (F9 v1). performance.view: son baseline artefaktı
 * (ofisvio:perf-baseline), site başına önbellek istatistiği, zamanlayıcı kalp
 * atışı, doctor özeti. performance.audit: baseline'ı yeniden ölçer (üretim dışı;
 * komut üretimde kendisi reddeder). performance.settings için ayar yok (v1).
 */
class PerformanceController extends Controller
{
    public function __construct(
        private readonly ContentService $contents,
        private readonly ContentCache $cache,
        private readonly AuthorizationService $authorization,
    ) {}

    private function baselinePath(): string
    {
        return storage_path('app/perf/baseline.json');
    }

    public function index(Request $request): View
    {
        $baseline = File::exists($this->baselinePath()) ? json_decode((string) File::get($this->baselinePath()), true) : null;
        $heartbeat = Cache::get(DoctorCommand::HEARTBEAT_KEY);

        return view('panel.performance.index', [
            'baseline' => is_array($baseline) ? $baseline : null,
            'sites' => $this->contents->allWebsites()->map(fn (Website $site) => ['website' => $site, 'stats' => $this->cache->stats($site), 'ttl' => $this->cache->ttl($site)]),
            'heartbeat' => is_string($heartbeat) ? $heartbeat : null,
            'heartbeatMax' => DoctorCommand::HEARTBEAT_MAX_MINUTES,
            'canAudit' => $this->authorization->can($request->user(), 'performance.audit') && config('app.env') !== 'production',
            'cacheStore' => (string) config('cache.default'),
            'dbDriver' => (string) config('database.default'),
        ]);
    }

    /** performance.audit: baseline'ı şimdi ölç (komut üretimde reddeder; burada da açılmaz). */
    public function measure(): RedirectResponse
    {
        if (config('app.env') === 'production') {
            return redirect()->route('panel.performance.index')->withErrors(['baseline' => 'Baseline ölçümü üretimde çalıştırılmaz; CI artefaktını kullanın.']);
        }

        $code = Artisan::call('ofisvio:perf-baseline', ['--out' => $this->baselinePath(), '--runs' => 2]);

        return redirect()->route('panel.performance.index')->with('status', $code === 0 ? 'Baseline ölçüldü.' : 'Ölçüm tamamlanamadı; konsol çıktısına bakın.');
    }

    /** performance.audit: doctor kontrol listesini çalıştır, sonucu göster. */
    public function doctor(): View
    {
        Artisan::call('ofisvio:doctor', ['--json' => true]);
        $out = json_decode(Artisan::output(), true);

        return view('panel.performance.doctor', ['report' => is_array($out) ? $out : ['ok' => false, 'rows' => []]]);
    }
}
