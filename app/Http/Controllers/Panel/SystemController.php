<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Integrations\ConnectionTester;
use App\Integrations\IntegrationCatalog;
use App\Integrations\SecretStore;
use App\Services\AuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * API & Entegrasyonlar merkezi + Sistem sağlığı (faz 52) — `/panel/ayarlar/api`, `/panel/ayarlar/saglik`.
 * Secret'lar yalnız env'de; ekran maskeli durum + env anahtar adı gösterir, forma alınmaz (config/integrations.php
 * kuralı). "Bağlantıyı test et" ConnectionTester ile gerçek yoklama yapar ve günlükler; sağlık sayfası
 * `ofisvio:doctor` kontrollerini aynen çalıştırır (tek kaynak).
 */
class SystemController extends Controller
{
    public function __construct(private readonly SecretStore $secrets, private readonly ConnectionTester $tester, private readonly AuditService $audit) {}

    public function api(Request $request): View
    {
        $providers = [];

        foreach ((array) config('integrations.providers') as $key => $p) {
            $providers[$key] = [
                'key' => $key, 'label' => (string) ($p['label'] ?? $key), 'category' => IntegrationCatalog::PROVIDER_CATEGORY[$key] ?? 'webhook',
                'enabled' => (bool) ($p['enabled'] ?? false), 'endpoint' => (string) ($p['base_url'] ?? ''), 'secrets' => $this->secrets->masked((string) $key), 'missing' => $this->secrets->missing((string) $key),
                'webhook' => $this->secrets->webhookSecret((string) $key) !== null, 'env' => IntegrationCatalog::PROVIDER_ENV[$key] ?? [], 'usage' => IntegrationCatalog::PROVIDER_USAGE[$key] ?? '',
                'last' => $this->tester->last((string) $key),
            ];
        }

        $core = [];

        foreach (IntegrationCatalog::CORE as $key => $c) {
            $core[$key] = $c + ['key' => $key, 'last' => $this->tester->last($key), 'current' => $this->coreSummary($key)];
        }

        return view('panel.settings.api', [
            'categories' => IntegrationCatalog::CATEGORIES,
            'providers' => $providers,
            'core' => $core,
            'environment' => (string) config('app.env'),
            'result' => $request->session()->get('test_result'),
        ]);
    }

    public function test(Request $request, string $key): RedirectResponse
    {
        $started = hrtime(true);
        $result = $this->tester->test($key);
        $duration = (int) ((hrtime(true) - $started) / 1e6);
        $this->tester->log($key, $result, $duration);
        $this->audit->record($request->user(), 'integration.tested', 'integration', null, [], ['key' => $key, 'level' => $result['level'], 'duration_ms' => $duration]);

        return redirect()->route('panel.settings.api.core')->with('test_result', ['key' => $key] + $result)->with('status', 'Bağlantı testi: '.$key.' → '.['ok' => 'çalışıyor', 'warn' => 'uyarı', 'fail' => 'hata'][$result['level']].' ('.$duration.' ms). '.$result['note']);
    }

    public function health(): View
    {
        Artisan::call('ofisvio:doctor', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        return view('panel.settings.health', [
            'rows' => is_array($report['rows'] ?? null) ? $report['rows'] : [],
            'ok' => (bool) ($report['ok'] ?? false),
            'environment' => (string) ($report['env'] ?? config('app.env')),
            'debug' => (bool) config('app.debug'),
            'recent' => $this->tester->recent(),
        ]);
    }

    public function recheck(Request $request): RedirectResponse
    {
        $this->audit->record($request->user(), 'system.health_checked', 'system', null, [], []);

        return redirect()->route('panel.settings.health')->with('status', 'Kontroller yeniden çalıştırıldı.');
    }

    private function coreSummary(string $key): string
    {
        return match ($key) {
            'database' => (string) config('database.default'),
            'cache' => (string) config('cache.default'),
            'queue' => (string) config('queue.default'),
            'mail' => (string) config('mail.default').(config('mail.default') === 'smtp' ? ' · '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port') : ''),
            'storage' => (string) config('filesystems.default').' · private: '.basename((string) config('filesystems.disks.private.root', '')),
            'scanner' => (string) config('ofisvio.kyc.scanner'),
            'scheduler' => 'schedule:run (cron)',
            'webhooks' => 'imzalı, replay toleransı '.config('integrations.webhook_tolerance_seconds').' sn',
            default => '',
        };
    }
}
