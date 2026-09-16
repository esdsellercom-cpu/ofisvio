<?php

namespace App\Http\Controllers;

use App\Integrations\WebhookReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gelen webhook ucu: /webhooks/{provider}. CSRF yok (sunucudan sunucuya), imza ve
 * zaman damgası WebhookReceiver'da doğrulanır; throttle:webhook. Cevap gövdesi
 * sağlayıcıya bilgi sızdırmaz (yalnız durum kodu + kısa neden).
 */
class WebhookController extends Controller
{
    public function __construct(private readonly WebhookReceiver $receiver) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $result = $this->receiver->receive($provider, $request);

        return response()->json(['ok' => $result['status'] < 300, 'reason' => $result['reason']], $result['status']);
    }
}
