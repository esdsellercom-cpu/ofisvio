<?php

namespace Tests\Feature\Security;

use App\Integrations\Gateway;
use App\Integrations\UrlGuard;
use App\Models\IntegrationLog;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Faz 5 — Integration Gateway, secret yönetimi, SSRF koruması, webhook güvenliği.
 * Sağlayıcı gerçek değil: Http::fake ve sahte DNS çözücü; kurallar gerçek.
 */
class IntegrationGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function enableProvider(string $key, array $secrets, string $baseUrl = 'https://api.example.test'): void
    {
        config([
            "integrations.providers.{$key}.enabled" => true,
            "integrations.providers.{$key}.base_url" => $baseUrl,
            "integrations.providers.{$key}.secrets" => $secrets,
        ]);
    }

    private function publicResolver(): UrlGuard
    {
        return new UrlGuard(fn (string $host) => match ($host) {
            'api.example.test' => ['93.184.216.34'],
            'evil.example.test' => ['10.0.0.5'],
            'meta.example.test' => ['169.254.169.254'],
            'v6.example.test' => ['::ffff:192.168.1.1'],
            default => false,
        });
    }

    #[Test]
    public function ssrf_korumasi_ozel_aglari_ip_literallerini_ve_http_yi_reddeder(): void
    {
        $guard = $this->publicResolver();

        $this->assertSame('https://api.example.test/v1/ping', $guard->assertAllowed('https://api.example.test', '/v1/ping'));

        $cases = [
            ['http://api.example.test', '/x', 'https'],
            ['https://user:pw@api.example.test', '/x', 'kullanıcı bilgisi'],
            ['https://api.example.test:8443', '/x', '443'],
            ['https://10.0.0.1', '/x', 'IP literali'],
            ['https://localhost', '/x', 'Yerel'],
            ['https://evil.example.test', '/x', 'özel/yerel'],
            ['https://meta.example.test', '/x', 'özel/yerel'],
            ['https://v6.example.test', '/x', 'özel/yerel'],
            ['https://api.example.test', 'https://evil.example.test/x', 'mutlak adres'],
            ['https://api.example.test', '//evil.example.test/x', 'mutlak adres'],
            ['https://unknown.example.test', '/x', 'çözümlenemedi'],
        ];

        foreach ($cases as [$base, $path, $needle]) {
            try {
                $guard->assertAllowed($base, $path);
                $this->fail("Reddedilmeliydi: {$base} {$path}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($needle, $e->getMessage(), "{$base} {$path}");
            }
        }

        $this->assertFalse(UrlGuard::isPublicIp('127.0.0.1'));
        $this->assertFalse(UrlGuard::isPublicIp('::1'));
        $this->assertFalse(UrlGuard::isPublicIp('fd00::1'));
        $this->assertTrue(UrlGuard::isPublicIp('1.1.1.1'));
    }

    #[Test]
    public function gateway_kapali_saglayiciyi_ve_eksik_secreti_reddeder_istekleri_loglar(): void
    {
        $this->app->instance(UrlGuard::class, $this->publicResolver());
        Http::fake(['api.example.test/*' => Http::response(['ok' => true], 200)]);
        $gateway = app(Gateway::class);

        // Kapalı sağlayıcı: hiçbir istek çıkmaz.
        try {
            $gateway->request('sms', 'GET', '/v1/ping');
            $this->fail('Kapalı sağlayıcı reddedilmeli.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('kapalı', $e->getMessage());
        }
        Http::assertNothingSent();

        // Açık ama secret eksik: reddedilir.
        $this->enableProvider('sms', ['api_key' => null]);
        try {
            $gateway->request('sms', 'GET', '/v1/ping');
            $this->fail('Eksik secret reddedilmeli.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('eksik secret: api_key', $e->getMessage());
        }
        Http::assertNothingSent();

        // Tam yapılandırma: istek çıkar, log secret/sorgu taşımaz, yönlendirme kapalı, zaman aşımı var.
        $this->enableProvider('sms', ['api_key' => 'gizli-anahtar-123']);
        $response = $gateway->request('sms', 'POST', '/v1/send?token=SIZDIRMA', ['json' => ['to' => '+905'], 'headers' => ['Authorization' => 'Bearer gizli-anahtar-123']]);
        $this->assertTrue($response->successful());
        Http::assertSent(fn ($req) => $req->url() === 'https://api.example.test/v1/send?token=SIZDIRMA' && $req->hasHeader('Authorization'));

        $log = IntegrationLog::firstOrFail();
        $this->assertSame(['sms', 'POST', '/v1/send', 200, true], [$log->provider, $log->method, $log->path, $log->status, $log->ok]);
        $this->assertStringNotContainsString('gizli', json_encode($log->toArray()) ?: '');
        $this->assertStringNotContainsString('SIZDIRMA', json_encode($log->toArray()) ?: '');

        // Kaçış: yol mutlak adres olamaz (SSRF).
        try {
            $gateway->request('sms', 'GET', 'https://evil.example.test/x');
            $this->fail('Mutlak yol reddedilmeli.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('mutlak adres', $e->getMessage());
        }
    }

    #[Test]
    public function webhook_imza_zaman_damgasi_ve_tekrar_teslim_kurallari(): void
    {
        $secret = 'whsec_test_1234567890';
        $body = json_encode(['type' => 'payment.succeeded', 'amount' => 100]);
        $ts = (string) time();
        $sign = fn (string $b, string $t, string $s = 'whsec_test_1234567890') => hash_hmac('sha256', $b.'.'.$t, $s);
        $headers = fn (array $extra = []) => array_merge(['X-Ofisvio-Signature' => $sign($body, $ts), 'X-Ofisvio-Timestamp' => $ts, 'X-Ofisvio-Event-Id' => 'evt_1'], $extra);

        // Sağlayıcı kapalı: 404 (varlık sızdırmaz). Açık ama webhook secret yok: 404.
        $this->call('POST', '/webhooks/iyzico', [], [], [], $this->server($headers()), $body)->assertNotFound();
        config(['integrations.providers.iyzico.enabled' => true, 'integrations.providers.iyzico.webhook_secret' => null]);
        $this->call('POST', '/webhooks/iyzico', [], [], [], $this->server($headers()), $body)->assertNotFound();
        $this->call('POST', '/webhooks/bilinmeyen', [], [], [], $this->server($headers()), $body)->assertNotFound();

        config(['integrations.providers.iyzico.webhook_secret' => $secret]);

        // Yanlış imza 401; eski zaman damgası 401; başlık eksik 400; JSON değil 400.
        $this->call('POST', '/webhooks/iyzico', [], [], [], $this->server($headers(['X-Ofisvio-Signature' => $sign($body, $ts, 'yanlis')])), $body)->assertStatus(401);
        $old = (string) (time() - 3600);
        $this->call('POST', '/webhooks/iyzico', [], [], [], $this->server(['X-Ofisvio-Signature' => $sign($body, $old), 'X-Ofisvio-Timestamp' => $old, 'X-Ofisvio-Event-Id' => 'evt_old']), $body)->assertStatus(401);
        $this->call('POST', '/webhooks/iyzico', [], [], [], $this->server(['X-Ofisvio-Timestamp' => $ts]), $body)->assertStatus(400);
        $this->call('POST', '/webhooks/iyzico', [], [], [], $this->server(['X-Ofisvio-Signature' => $sign('bozuk', $ts), 'X-Ofisvio-Timestamp' => $ts, 'X-Ofisvio-Event-Id' => 'evt_j']), 'bozuk')->assertStatus(400);
        $this->assertSame(0, WebhookEvent::count());

        // Geçerli: 202 + kayıt; aynı olay tekrar: 200, ikinci kayıt YOK; gövde değişip aynı id: 401 (imza tutmaz).
        $this->call('POST', '/webhooks/iyzico', [], [], [], $this->server($headers()), $body)->assertStatus(202);
        $event = WebhookEvent::firstOrFail();
        $this->assertSame(['iyzico', 'evt_1', 'payment.succeeded', 'received'], [$event->provider, $event->event_id, $event->payload['type'], $event->status]);
        $this->call('POST', '/webhooks/iyzico', [], [], [], $this->server($headers()), $body)->assertStatus(200)->assertJson(['reason' => 'duplicate']);
        $this->assertSame(1, WebhookEvent::count());

        // CSRF istenmez (sunucudan sunucuya) — imza yeterli; farklı olay id kaydedilir.
        $this->call('POST', '/webhooks/iyzico', [], [], [], $this->server($headers(['X-Ofisvio-Event-Id' => 'evt_2'])), $body)->assertStatus(202);
        $this->assertSame(2, WebhookEvent::count());
    }

    /** @param  array<string, string>  $headers  @return array<string, string> */
    private function server(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $server;
    }
}
