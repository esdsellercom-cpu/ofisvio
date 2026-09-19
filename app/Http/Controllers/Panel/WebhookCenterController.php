<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\WebhookEvents;
use App\Webhooks\WebhookService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Webhook merkezi (faz 61c): uç listesi → oluştur/düzenle → test gönder → teslimat logu → başarısızı tekrar gönder.
 * Tüm rotalar webhooks.manage (route middleware). Secret hiçbir görünüme girmez; üretildiği anda bir kez flash ile
 * gösterilir (oturum, log değil).
 */
class WebhookCenterController extends Controller
{
    public function __construct(private readonly WebhookService $webhooks) {}

    public function index(): View
    {
        return view('panel.settings.webhooks.index', [
            'endpoints' => $this->webhooks->endpoints(),
            'stats' => $this->webhooks->stats(),
            'events' => WebhookEvents::REGISTRY,
        ]);
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $result = $this->webhooks->create($request->user(), $this->input($request));
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['webhook' => $e->getMessage()]);
        }

        return redirect()->route('panel.settings.webhooks.edit', $result['endpoint'])
            ->with('status', 'Webhook oluşturuldu. Şimdi "Test gönder" ile doğrulayın.')
            ->with('webhook_secret', $result['secret']);
    }

    public function edit(WebhookEndpoint $endpoint): View
    {
        return $this->form($endpoint);
    }

    public function update(Request $request, WebhookEndpoint $endpoint): RedirectResponse
    {
        try {
            $result = $this->webhooks->update($request->user(), $endpoint, $this->input($request));
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['webhook' => $e->getMessage()]);
        }

        return back()->with('status', 'Webhook kaydedildi.')->with('webhook_secret', $result['secret']);
    }

    public function toggle(Request $request, WebhookEndpoint $endpoint): RedirectResponse
    {
        $endpoint = $this->webhooks->toggle($request->user(), $endpoint);

        return back()->with('status', $endpoint->is_active ? 'Webhook aktifleştirildi.' : 'Webhook pasife alındı.');
    }

    public function destroy(Request $request, WebhookEndpoint $endpoint): RedirectResponse
    {
        $this->webhooks->delete($request->user(), $endpoint);

        return redirect()->route('panel.settings.webhooks.index')->with('status', 'Webhook silindi.');
    }

    public function test(Request $request, WebhookEndpoint $endpoint): RedirectResponse
    {
        $delivery = $this->webhooks->sendTest($request->user(), $endpoint);

        return redirect()->route('panel.settings.webhooks.deliveries', ['uc' => $endpoint->id])
            ->with('status', 'Test gönderimi kuyruğa alındı ('.$delivery->delivery_id.'). Sonuç aşağıdaki logda görünür.');
    }

    public function deliveries(Request $request): View
    {
        $endpointId = $request->query('uc') !== null && $request->query('uc') !== '' ? (int) $request->query('uc') : null;

        return view('panel.settings.webhooks.deliveries', [
            'deliveries' => $this->webhooks->deliveries($endpointId, (string) $request->query('durum', ''), (string) $request->query('olay', '')),
            'endpoints' => $this->webhooks->endpoints(),
            'endpointId' => $endpointId,
            'status' => (string) $request->query('durum', ''),
            'event' => (string) $request->query('olay', ''),
            'stats' => $this->webhooks->stats(),
        ]);
    }

    public function resend(Request $request, WebhookDelivery $delivery): RedirectResponse
    {
        try {
            $this->webhooks->resend($request->user(), $delivery);
        } catch (DomainException $e) {
            return back()->withErrors(['webhook' => $e->getMessage()]);
        }

        return back()->with('status', 'Teslimat yeniden kuyruğa alındı ('.$delivery->delivery_id.').');
    }

    private function form(?WebhookEndpoint $endpoint): View
    {
        return view('panel.settings.webhooks.form', [
            'endpoint' => $endpoint,
            'grouped' => WebhookEvents::grouped(),
            'recent' => $endpoint === null ? collect() : $this->webhooks->recent($endpoint),
            'retryMax' => WebhookService::RETRY_MAX,
            'timeoutMax' => WebhookService::TIMEOUT_MAX,
        ]);
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return [
            'name' => $request->input('name'),
            'url' => $request->input('url'),
            'secret' => $request->input('secret'),
            'rotate_secret' => $request->boolean('rotate_secret'),
            'events' => (array) $request->input('events', []),
            'is_active' => $request->boolean('is_active'),
            'retry_max' => $request->input('retry_max', 3),
            'timeout_seconds' => $request->input('timeout_seconds', 10),
            'description' => $request->input('description'),
        ];
    }
}
