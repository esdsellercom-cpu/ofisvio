<?php

namespace Tests\Feature\Panel;

use App\Integrations\UrlGuard;
use App\Models\AuditLog;
use App\Models\IntegrationLog;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\GeoService;
use App\Services\LeadService;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 61c — Webhook merkezi: oluştur (secret şifreli, bir kez gösterilir) → test gönder (HMAC imza, Gateway) → log
 * (gövde/secret loglanmaz) → yeniden deneme (retry_max, artan aralık) → tekrar gönder; olaylar servislerden yayılır
 * ve saklanan payload kişisel veriyi maskeler; yetkisiz roller 403; https/genel ana bilgisayar zorunlu.
 */
class WebhookCenterTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['157.240.1.1'])); // DNS: genel IP
    }

    #[Test]
    public function webhook_olustur_test_gonder_logla_yeniden_dene_ve_tekrar_gonder(): void
    {
        $admin = $this->staff('system_admin');
        $base = '/panel/ayarlar/webhooks';

        // Yetki: webhooks.manage yalnız system/super admin.
        $this->actingAs($this->staff('operations_admin'))->get($base)->assertForbidden();
        $this->actingAs($this->staff('finance_admin'))->get($base.'/yeni')->assertForbidden();
        $this->actingAs($admin)->get($base)->assertOk()->assertSee('Webhook merkezi')->assertSee('Henüz webhook yok')->assertSee('booking.created');

        // Geçersiz adresler reddedilir: http, IP literali, yerel ad.
        foreach (['http://crm.example.com/hook', 'https://10.0.0.5/hook', 'https://localhost/hook'] as $bad) {
            $this->actingAs($admin)->from($base.'/yeni')->post($base, ['name' => 'CRM', 'url' => $bad, 'events' => ['lead.created'], 'is_active' => 1])->assertSessionHasErrors('webhook');
        }
        $this->assertSame(0, WebhookEndpoint::query()->count());

        // Oluştur: secret boş → üretilir, bir kez flash ile gösterilir; DB'de düz metin yok; ekranda yok; audit'te yok.
        $response = $this->actingAs($admin)->post($base, ['name' => 'CRM', 'url' => 'https://crm.example.com/hooks/ofisvio', 'events' => ['lead.created', 'location.created', 'bilinmeyen.olay'], 'is_active' => 1, 'retry_max' => 3, 'timeout_seconds' => 5, 'description' => 'Satış CRM'])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('webhook_secret');
        $secret = (string) session('webhook_secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $endpoint = WebhookEndpoint::query()->firstOrFail();
        $this->assertSame(['lead.created', 'location.created'], $endpoint->events);
        $this->assertStringNotContainsString($secret, (string) DB::table('webhook_endpoints')->value('secret'));
        $this->assertSame($secret, $endpoint->secret);
        $this->assertStringNotContainsString($secret, json_encode(AuditLog::query()->where('action', 'webhook.created')->firstOrFail()->toArray()));
        $this->actingAs($admin)->get((string) $response->headers->get('Location'))->assertOk()->assertSee($secret); // yalnız bu yanıtta (flash)
        $this->actingAs($admin)->get($base.'/'.$endpoint->id)->assertOk()->assertSee('CRM')->assertDontSee($secret);
        $this->actingAs($admin)->get($base)->assertOk()->assertSee('crm.example.com')->assertSee('Form gönderildi')->assertDontSee($secret);

        // Test gönderimi: alıcı 3 kez 500 verir → retry_max=3 → toplam 4 deneme, failed; sonra tekrar gönder → 200 → success.
        $seen = [];
        $fail = true;
        Http::fake(function (Request $request) use (&$seen, &$fail, $secret) {
            $seen[] = $request;
            $this->assertSame('https://crm.example.com/hooks/ofisvio', $request->url());
            $this->assertSame('ping', $request->header('X-Ofisvio-Event')[0] ?? null);
            $ts = $request->header('X-Ofisvio-Timestamp')[0] ?? '';
            $this->assertSame('t='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$request->body(), $secret), $request->header('X-Ofisvio-Signature')[0] ?? null);
            $this->assertStringContainsString('"event":"ping"', $request->body());

            return $fail ? Http::response('boom', 500) : Http::response('', 204);
        });

        $this->actingAs($admin)->post($base.'/'.$endpoint->id.'/test')->assertRedirect($base.'/teslimatlar?uc='.$endpoint->id);
        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertSame('ping', $delivery->event);
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(4, $delivery->attempts); // 1 + retry_max (sync kuyruk gecikmeyi beklemez)
        $this->assertSame(500, $delivery->response_status);
        $this->assertCount(4, $seen);
        $this->assertNotNull($endpoint->fresh()->last_failure_at);

        // Gateway logu: yalnız ana bilgisayar; gövde, imza, secret yok.
        $logs = IntegrationLog::query()->where('provider', 'webhook_out')->get();
        $this->assertCount(4, $logs);
        $this->assertSame('crm.example.com', $logs->first()->path);
        $this->assertStringNotContainsString($secret, json_encode($logs->toArray()));
        $this->assertStringNotContainsString('hooks/ofisvio', json_encode($logs->toArray()));

        // Teslimat logu ekranı: durum, deneme, HTTP; tekrar gönder butonu.
        $this->actingAs($admin)->get($base.'/teslimatlar')->assertOk()->assertSee('Başarısız')->assertSee('4 / 4')->assertSee('Tekrar gönder')->assertDontSee($secret);

        // Tekrar gönder → tek deneme, 204 → success; endpoint last_success_at dolar; listede "Bağlı".
        $fail = false;
        $this->actingAs($admin)->post($base.'/teslimatlar/'.$delivery->id.'/tekrar')->assertRedirect()->assertSessionHas('status');
        $delivery->refresh();
        $this->assertSame('success', $delivery->status);
        $this->assertSame(5, $delivery->attempts);
        $this->assertSame(204, $delivery->response_status);
        $this->assertTrue($delivery->manual);
        $this->assertNotNull($endpoint->fresh()->last_success_at);
        $this->actingAs($admin)->from($base.'/teslimatlar')->post($base.'/teslimatlar/'.$delivery->id.'/tekrar')->assertSessionHasErrors('webhook'); // başarılı tekrar gönderilmez
        $this->assertTrue(AuditLog::query()->where('action', 'webhook.resent')->exists());
        $this->actingAs($admin)->get('/panel/ayarlar/entegrasyonlar/webhook_out')->assertOk()->assertSee('Bağlı')->assertSee('Webhook merkezi');

        // Pasife al → olay gitmez; sil → teslimatlar da gider.
        $this->actingAs($admin)->post($base.'/'.$endpoint->id.'/durum')->assertRedirect();
        $this->assertFalse($endpoint->fresh()->is_active);
        $this->actingAs($admin)->post($base.'/'.$endpoint->id.'/sil')->assertRedirect($base);
        $this->assertSame(0, WebhookEndpoint::query()->count());
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    #[Test]
    public function olaylar_servislerden_yayilir_payload_logda_maskelenir_ve_pasif_uc_almaz(): void
    {
        $admin = $this->staff('system_admin');
        $this->actingAs($admin)->post('/panel/ayarlar/webhooks', ['name' => 'CRM', 'url' => 'https://crm.example.com/in', 'secret' => 'ortak-gizli-anahtar-123456', 'events' => ['lead.created', 'location.created'], 'is_active' => 1, 'retry_max' => 0, 'timeout_seconds' => 5])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/panel/ayarlar/webhooks', ['name' => 'Pasif', 'url' => 'https://other.example.com/in', 'events' => ['lead.created'], 'is_active' => 0, 'retry_max' => 0, 'timeout_seconds' => 5])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/panel/ayarlar/webhooks', ['name' => 'Başka olay', 'url' => 'https://third.example.com/in', 'events' => ['booking.created'], 'is_active' => 1, 'retry_max' => 0, 'timeout_seconds' => 5])->assertSessionHasNoErrors();
        $this->assertSame('ortak-gizli-anahtar-123456', WebhookEndpoint::query()->where('name', 'CRM')->firstOrFail()->secret);

        $bodies = [];
        Http::fake(function (Request $request) use (&$bodies) {
            $bodies[] = ['url' => $request->url(), 'body' => $request->body(), 'event' => $request->header('X-Ofisvio-Event')[0] ?? null];

            return Http::response(['ok' => true]);
        });

        // Vitrin formu → lead.created; gönderilen gövde e-postayı taşır, saklanan kopya maskeler.
        $lead = app(LeadService::class)->capture(['kind' => 'quote', 'name' => 'Ayşe Yılmaz', 'email' => 'ayse@example.com', 'phone' => '+905551112233', 'solution' => 'virtual_office'], ['ip' => '203.0.113.9']);
        $this->assertCount(1, $bodies); // yalnız aktif + abone uç
        $this->assertSame('https://crm.example.com/in', $bodies[0]['url']);
        $this->assertSame('lead.created', $bodies[0]['event']);
        $this->assertStringContainsString('ayse@example.com', $bodies[0]['body']);
        $this->assertStringContainsString('"lead_id":'.$lead->id, $bodies[0]['body']);
        $delivery = WebhookDelivery::query()->where('event', 'lead.created')->firstOrFail();
        $this->assertSame('success', $delivery->status);
        $this->assertSame('***', $delivery->payload['data']['email']);
        $this->assertSame('***', $delivery->payload['data']['name']);
        $this->assertSame($lead->id, $delivery->payload['data']['lead_id']);
        $this->assertStringNotContainsString('ayse@example.com', (string) DB::table('webhook_deliveries')->where('id', $delivery->id)->value('body')); // şifreli
        $this->assertStringNotContainsString('ayse@example.com', (string) DB::table('webhook_deliveries')->where('id', $delivery->id)->value('payload'));

        // Lokasyon oluşturma → location.created.
        app(GeoService::class)->create(['name' => 'Kadıköy Şube', 'city' => 'İstanbul']);
        $this->assertCount(2, $bodies);
        $this->assertSame('location.created', $bodies[1]['event']);
        $this->assertStringContainsString('Kadıköy', $bodies[1]['body']);

        // Teslimat logu ekranı; secret ve e-posta hiçbir yanıtta yok.
        $page = $this->actingAs($admin)->get('/panel/ayarlar/webhooks/teslimatlar?olay=lead.created')->assertOk();
        $page->assertSee('lead.created')->assertSee('Başarılı')->assertDontSee('ayse@example.com')->assertDontSee('ortak-gizli-anahtar');
        $this->actingAs($admin)->get('/panel/ayarlar/webhooks')->assertOk()->assertSee('2 aktif')->assertDontSee('ortak-gizli-anahtar');
    }

    #[Test]
    public function gelen_webhook_buyuk_govdeyi_reddeder(): void
    {
        // Regresyon (smoke buldu): Laravel 13 web grubu PreventRequestForgery — webhooks/* CSRF dışında olmalı, yoksa üretimde 419.
        // Testte CSRF atlandığından davranış değil, kayıtlı istisna listesi doğrulanır.
        $never = (new \ReflectionClass(PreventRequestForgery::class))->getStaticPropertyValue('neverVerify');
        $this->assertContains('webhooks/*', $never, 'gelen webhook CSRF istisnası bootstrap/app.php içinde tanımlı olmalı');
        $this->assertTrue(in_array(PreventRequestForgery::class, app(Kernel::class)->getMiddlewareGroups()['web'], true));

        config(['integrations.providers.sms.enabled' => true, 'integrations.providers.sms.secrets.api_key' => 'x', 'integrations.providers.sms.webhook_secret' => 'gelen-secret']);
        $body = str_repeat('a', 262145);
        $this->call('POST', '/webhooks/sms', [], [], [], ['HTTP_X-Ofisvio-Timestamp' => (string) time(), 'HTTP_X-Ofisvio-Signature' => 'x', 'HTTP_X-Ofisvio-Event-Id' => 'e1', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(413);
    }
}
