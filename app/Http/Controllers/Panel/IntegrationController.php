<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Integrations\SecretStore;
use App\Services\NotificationService;
use Illuminate\Contracts\View\View;

/**
 * Entegrasyonlar & API (faz 39, artifact §18): sağlayıcı geçidi durumu (env ile açılır,
 * secret'lar maskeli), bildirim kanalı sağlığı (son 7 gün), gelen webhook uçları.
 * Dış (public) API yok; ekran bunu açıkça söyler, sahte uç listelemez.
 */
class IntegrationController extends Controller
{
    public function __construct(private readonly SecretStore $secrets, private readonly NotificationService $notifications) {}

    public function index(): View
    {
        return view('panel.integrations.index', [
            'providers' => collect((array) config('integrations.providers'))->map(fn (array $p, string $key) => [
                'key' => $key,
                'label' => (string) ($p['label'] ?? $key),
                'enabled' => (bool) ($p['enabled'] ?? false),
                'base_url' => (string) ($p['base_url'] ?? ''),
                'secrets' => $this->secrets->masked($key),
                'missing' => $this->secrets->missing($key),
                'webhook' => $this->secrets->webhookSecret($key) !== null,
            ]),
            'health' => $this->notifications->channelHealth(7),
            'timeout' => (int) config('integrations.timeout_seconds'),
        ]);
    }
}
