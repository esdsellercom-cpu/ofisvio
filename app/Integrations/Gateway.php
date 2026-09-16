<?php

namespace App\Integrations;

use App\Models\IntegrationLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Integration Gateway (faz 5): dış sağlayıcıya giden TEK yol.
 *
 *   Uygulama → Gateway::request(provider, method, path, options) → Adapter/Provider
 *
 * Her istek: sağlayıcı açık mı (kapalıysa RED) → SSRF denetimi (UrlGuard) →
 * zaman aşımı → yönlendirme YOK → integration_logs (sağlayıcı, yöntem, yol,
 * durum, süre; asla başlık/gövde/secret değil). Kimlik bilgisini adaptör
 * SecretStore'dan alır ve yalnız başlığa koyar; log'a girmez.
 *
 * ArchitectureTest: Http:: facade'i yalnız bu sınıfta kullanılır.
 */
class Gateway
{
    public function __construct(
        private readonly SecretStore $secrets,
        private readonly UrlGuard $guard,
    ) {}

    /**
     * @param  array{headers?: array<string, string>, json?: array<string, mixed>, query?: array<string, mixed>}  $options
     */
    public function request(string $provider, string $method, string $path, array $options = []): Response
    {
        $config = config("integrations.providers.{$provider}");

        if (! is_array($config)) {
            throw new RuntimeException("Bilinmeyen sağlayıcı: {$provider}");
        }

        if (! $this->secrets->enabled($provider)) {
            throw new RuntimeException("{$provider} sağlayıcısı kapalı (env ile açılır).");
        }

        $missing = $this->secrets->missing($provider);

        if ($missing !== []) {
            throw new RuntimeException("{$provider} için eksik secret: ".implode(', ', $missing));
        }

        $url = $this->guard->assertAllowed((string) ($config['base_url'] ?? ''), $path);
        $method = strtoupper($method);
        $started = hrtime(true);
        $status = null;
        $error = null;

        try {
            $pending = Http::timeout((int) config('integrations.timeout_seconds', 10))
                ->withOptions(['allow_redirects' => false])
                ->withHeaders($options['headers'] ?? [])
                ->acceptJson();

            if (isset($options['query'])) {
                $pending = $pending->withQueryParameters($options['query']);
            }

            $response = isset($options['json'])
                ? $pending->send($method, $url, ['json' => $options['json']])
                : $pending->send($method, $url);

            $status = $response->status();

            return $response;
        } catch (ConnectionException $e) {
            $error = 'bağlantı: '.mb_substr($e->getMessage(), 0, 190);

            throw new RuntimeException("{$provider} erişilemez: ".$error, previous: $e);
        } finally {
            IntegrationLog::create([
                'provider' => $provider,
                'method' => $method,
                'path' => mb_substr(strtok($path, '?') ?: $path, 0, 190), // sorgu dizgisi (token taşıyabilir) loglanmaz
                'status' => $status,
                'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
                'ok' => $status !== null && $status >= 200 && $status < 300,
                'error' => $error,
            ]);
        }
    }
}
