<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationHub;
use App\Integrations\IntegrationRegistry;
use App\Services\AuthorizationService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Entegrasyon merkezi (faz 61b): liste → ayar ekranı (Bağlan → bilgileri gir → test et → aktifleştir) → sağlık →
 * loglar. integrations.view görür; integrations.manage yazar/test eder/aktifleştirir; secret girişi secrets.manage.
 * Secret değeri hiçbir yanıta girmez (maskeli); audit yalnız alan adı yazar.
 */
class IntegrationHubController extends Controller
{
    public function __construct(private readonly IntegrationHub $hub, private readonly AuthorizationService $authorization) {}

    public function index(Request $request): View
    {
        return view('panel.settings.integrations.index', [
            'categories' => $this->hub->overview(),
            'canManage' => $this->authorization->can($request->user(), 'integrations.manage'),
        ]);
    }

    public function show(Request $request, string $key): View
    {
        $def = IntegrationRegistry::definition($key);
        abort_if($def === null, 404);
        $user = $request->user();

        return view('panel.settings.integrations.show', [
            'key' => $key,
            'def' => $def,
            'category' => IntegrationRegistry::CATEGORIES[$def['category']],
            'status' => $this->hub->status($key),
            'fields' => in_array($def['kind'], ['link', 'planned'], true) ? [] : $this->hub->formFields($key),
            'canManage' => $this->authorization->can($user, 'integrations.manage'),
            'canSecrets' => $this->authorization->can($user, 'secrets.manage'),
            'recent' => $this->hub->recentLogs($key),
        ]);
    }

    public function save(Request $request, string $key): RedirectResponse
    {
        abort_if(IntegrationRegistry::definition($key) === null, 404);
        $input = (array) $request->input('f', []);
        $enabled = $request->has('enabled_choice') ? match ((string) $request->input('enabled_choice')) {
            'on' => true, 'off' => false, default => null
        } : null;

        try {
            $this->hub->save($request->user(), $key, $input, $enabled, $this->authorization->can($request->user(), 'secrets.manage'));
        } catch (DomainException $e) {
            return back()->withErrors(['integration' => $e->getMessage()]);
        }

        return back()->with('status', 'Kaydedildi. Şimdi "Bağlantıyı test et" ile doğrulayın.');
    }

    public function test(Request $request, string $key): RedirectResponse
    {
        abort_if(IntegrationRegistry::definition($key) === null, 404);
        $result = $this->hub->test($key);

        return back()->with($result['level'] === 'ok' ? 'status' : 'test_error', ($result['level'] === 'ok' ? 'Bağlantı başarılı — ' : 'Bağlantı başarısız — ').$result['note']);
    }

    public function health(): View
    {
        $groups = ['API' => ['ai', 'search_console', 'analytics', 'pagespeed', 'indexnow', 'google_maps'], 'Webhook' => ['webhook_in', 'webhook_out'], 'E-posta' => ['mail'], 'Ödeme' => ['iyzico', 'efatura'], 'Mesajlaşma' => ['sms', 'whatsapp'], 'AI' => ['ai'], 'Harita' => ['google_maps'], 'Depolama' => ['storage']];
        $rows = [];

        foreach ($groups as $group => $keys) {
            foreach ($keys as $key) {
                $def = IntegrationRegistry::definition($key);

                if ($def !== null) {
                    $rows[$group][] = $this->hub->status($key) + ['key' => $key, 'label' => $def['label']];
                }
            }
        }

        return view('panel.settings.integrations.health', ['rows' => $rows, 'incoming' => $this->hub->incomingEvents()]);
    }

    public function logs(Request $request): View
    {
        $provider = trim((string) $request->query('saglayici', ''));
        $only = (string) $request->query('durum', 'all');

        return view('panel.settings.integrations.logs', [
            'logs' => $this->hub->logs($provider, $only === 'error'),
            'providers' => $this->hub->logProviders(),
            'provider' => $provider,
            'only' => $only,
            'stats' => $this->hub->logStats(),
        ]);
    }
}
