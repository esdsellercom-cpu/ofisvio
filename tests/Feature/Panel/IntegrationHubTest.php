<?php

namespace Tests\Feature\Panel;

use App\Integrations\ConnectionTester;
use App\Integrations\IntegrationConfigRepository;
use App\Integrations\IntegrationHub;
use App\Integrations\SecretStore;
use App\Models\AuditLog;
use App\Models\IntegrationLog;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 61b — Entegrasyon merkezi: liste/durumlar, ayar ekranı (Bağlan → bilgileri gir → test et → aktifleştir),
 * secret'lar şifreli (encryption-at-rest), maskeli görünüm, "yeni anahtar gir", env önceliği/üst yazımı, bağlantı
 * testi Gateway üzerinden (secret loga girmez), izinler (integrations.* / secrets.manage), audit alan adı yazar,
 * sağlık ve log ekranları.
 */
class IntegrationHubTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
    }

    #[Test]
    public function liste_ve_ayar_ekrani_izinlere_gore_acilir_ve_link_turleri_yonlendirir(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // integrations.view var, manage yok
        $finance = $this->staff('finance_admin');

        $this->actingAs($finance)->get('/panel/ayarlar/api')->assertForbidden();
        $this->actingAs($ops)->get('/panel/ayarlar/api')->assertOk()->assertSee('Entegrasyon merkezi')->assertSee('AI sağlayıcısı (Anthropic)')->assertSee('SMTP / e-posta')->assertSee('Pasif')->assertSee('Harita / konum');
        $this->actingAs($admin)->get('/panel/ayarlar/entegrasyonlar')->assertRedirect('/panel/ayarlar/api');
        $this->actingAs($ops)->get('/panel/ayarlar/entegrasyonlar/ai')->assertOk()->assertSee('Bilgileri girin')->assertSee('salt okunur');
        $this->actingAs($ops)->post('/panel/ayarlar/entegrasyonlar/ai', ['f' => ['model' => 'x']])->assertForbidden();
        $this->actingAs($admin)->get('/panel/ayarlar/entegrasyonlar/crm')->assertOk()->assertSee('başka bir ekranda');
        $this->actingAs($admin)->get('/panel/ayarlar/entegrasyonlar/bilinmeyen')->assertNotFound();
        $this->actingAs($admin)->get('/panel/ayarlar/entegrasyonlar/saglik')->assertOk()->assertSee('Entegrasyon sağlığı')->assertSee('Ödeme')->assertSee('Harita');
        $this->actingAs($admin)->get('/panel/ayarlar/entegrasyonlar/loglar')->assertOk()->assertSee('API log ve izleme');
        $this->actingAs($admin)->get('/panel/ayarlar/api/cekirdek')->assertOk()->assertSee('Veritabanı');
        $this->actingAs($admin)->get('/panel/operasyon')->assertOk()->assertSee('Entegrasyon merkezi');
    }

    #[Test]
    public function api_anahtari_sifreli_saklanir_maskelenir_env_ustune_yazar_ve_test_gateway_uzerinden_calisir(): void
    {
        $admin = $this->staff('system_admin');
        $base = '/panel/ayarlar/entegrasyonlar/ai';

        // 1) Anahtar gir + model + aktifleştir.
        $this->actingAs($admin)->post($base, ['f' => ['api_key' => 'sk-ant-panel-1234567890', 'model' => 'claude-sonnet-5', 'timeout' => '90'], 'enabled_choice' => 'on'])->assertRedirect()->assertSessionHasNoErrors();

        // DB'de düz metin yok; şifreli değer çözülünce eşleşir.
        $row = DB::table('integration_secrets')->where('provider', 'ai')->where('field', 'api_key')->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('sk-ant-panel', (string) $row->value);
        $this->assertSame('sk-ant-panel-1234567890', Crypt::decryptString((string) $row->value));

        // SecretStore panel değerini görür; env boşken panel devrede; aktif.
        $store = app(SecretStore::class);
        $this->assertTrue($store->enabled('ai'));
        $this->assertSame('sk-ant-panel-1234567890', $store->get('ai', 'api_key'));
        $this->assertSame('90', (string) $store->config('ai', 'timeout'));
        $this->assertStringContainsString('panel', $store->masked('ai')['api_key']);

        // Ekranda değer yok, maske var; audit'te değer yok, alan adı var.
        $page = $this->actingAs($admin)->get($base)->assertOk();
        $page->assertSee('••••••••••••')->assertSee('Yeni anahtar gir')->assertDontSee('sk-ant-panel');
        $audit = AuditLog::query()->where('action', 'integration.updated')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('sk-ant-panel', json_encode($audit->toArray()));
        $this->assertStringContainsString('api_key', json_encode($audit->after ?? $audit->toArray()));

        // 2) Bağlantı testi: Gateway → /v1/models, x-api-key başlıkta; log'da anahtar yok; sonuç kullanıcı dostu.
        $reject = false; // ilk kayıtlı fake tüm istekleri karşılar; ikinci fake etkisizdir → bayrakla yönet
        Http::fake(function (Request $request) use (&$reject) {
            $this->assertSame('sk-ant-panel-1234567890', $request->header('x-api-key')[0] ?? null);

            if ($reject) {
                return Http::response(['error' => ['message' => 'invalid x-api-key']], 401);
            }

            return str_contains($request->url(), '/v1/models') ? Http::response(['data' => [['id' => 'claude-sonnet-5']]]) : Http::response([], 404);
        });
        $this->actingAs($admin)->post($base.'/test')->assertRedirect()->assertSessionHas('status');
        $this->assertStringContainsString('Bağlantı başarılı', (string) session('status'));
        $log = IntegrationLog::query()->where('provider', 'ai')->latest('id')->firstOrFail();
        $this->assertTrue($log->ok);
        $this->assertStringNotContainsString('sk-ant', (string) json_encode($log->toArray()));
        $this->assertTrue(IntegrationLog::query()->where('provider', 'health:ai')->where('ok', true)->exists());
        $this->actingAs($admin)->get('/panel/ayarlar/api')->assertOk()->assertSee('Bağlı');

        // Hatalı anahtar → kullanıcı dostu hata + durum 🔴.
        $reject = true;
        $this->actingAs($admin)->post($base.'/test')->assertRedirect()->assertSessionHas('test_error');
        $this->assertStringContainsString('reddedildi', (string) session('test_error'));
        $this->actingAs($admin)->get('/panel/ayarlar/api')->assertOk()->assertSee('Hata');

        // 3) Boş secret mevcut anahtarı korur; yeni anahtar üzerine yazar; sil → env'e döner (env boş → eksik).
        $this->actingAs($admin)->post($base, ['f' => ['api_key' => '', 'model' => 'claude-opus-5'], 'enabled_choice' => 'on'])->assertRedirect();
        $this->assertSame('sk-ant-panel-1234567890', app(SecretStore::class)->get('ai', 'api_key'));
        $this->assertSame('claude-opus-5', app(SecretStore::class)->config('ai', 'model'));
        $this->actingAs($admin)->post($base, ['f' => ['api_key' => 'sk-ant-new-0987654321'], 'enabled_choice' => 'on'])->assertRedirect();
        $this->assertSame('sk-ant-new-0987654321', app(SecretStore::class)->get('ai', 'api_key'));
        $this->actingAs($admin)->post($base, ['f' => ['clear_api_key' => 1], 'enabled_choice' => 'off'])->assertRedirect();
        $this->assertFalse(app(SecretStore::class)->has('ai', 'api_key'));
        $this->assertFalse(app(SecretStore::class)->enabled('ai'));

        // 4) Env doluyken panel boşsa env kullanılır; panel doluysa panel önce gelir.
        config(['integrations.providers.ai.secrets.api_key' => 'sk-env-1234567890']);
        app(IntegrationConfigRepository::class)->forget();
        $this->assertSame('sk-env-1234567890', app(SecretStore::class)->get('ai', 'api_key'));
        $this->actingAs($admin)->get($base)->assertOk()->assertSee('(env)');
        $this->actingAs($admin)->post($base, ['f' => ['api_key' => 'sk-panel-override-123'], 'enabled_choice' => 'env'])->assertRedirect();
        $this->assertSame('sk-panel-override-123', app(SecretStore::class)->get('ai', 'api_key'));

        // 5) Geçersiz URL reddedilir.
        $this->actingAs($admin)->from($base)->post($base, ['f' => ['base_url' => 'http://insecure.example']])->assertSessionHasErrors('integration');
    }

    #[Test]
    public function smtp_ayari_calisma_zamani_configine_uygulanir_ve_secret_yetkisi_ayridir(): void
    {
        // Boot (AppServiceProvider) yalnız IntegrationRuntime çözer: ConnectionTester → MalwareScanner zinciri .env'siz composer
        // `package:discover` (production varsayımı, KYC_SCANNER denetimi) sırasında tetiklenmemeli — CI kırılmıştı (faz 61c).
        $this->assertFalse($this->app->resolved(ConnectionTester::class));
        $this->assertFalse($this->app->resolved(IntegrationHub::class));

        $admin = $this->staff('system_admin');
        $base = '/panel/ayarlar/entegrasyonlar/mail';

        $this->actingAs($admin)->post($base, ['f' => ['host' => 'smtp.example.com', 'port' => '465', 'username' => 'posta@ofisvio.test', 'password' => 'gizli-parola-123', 'encryption' => 'ssl', 'from_name' => 'Ofisvio', 'from_address' => 'merhaba@ofisvio.test']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('smtp.example.com', DB::table('integration_settings')->where('provider', 'mail')->value('config') !== null ? json_decode(DB::table('integration_settings')->where('provider', 'mail')->value('config'), true)['host'] : null);
        $this->assertStringNotContainsString('gizli-parola', (string) DB::table('integration_secrets')->where('provider', 'mail')->value('value'));

        // Çalışma zamanı: yeni istekte config uygulanır (AppServiceProvider boot → applyRuntime).
        app(IntegrationConfigRepository::class)->forget();
        app(IntegrationHub::class)->applyRuntime();
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('gizli-parola-123', config('mail.mailers.smtp.password'));
        $this->assertSame('merhaba@ofisvio.test', config('mail.from.address'));
        $this->assertSame('smtp', config('mail.default'));
        $this->actingAs($admin)->get($base)->assertOk()->assertSee('smtp.example.com')->assertDontSee('gizli-parola')->assertSee('••••••••••••');

        // Secret yetkisi olmayan ama integrations.manage'ı olan kullanıcı yok (matris); manage yetkisi olmayan operations_admin secret giremez.
        $ops = $this->staff('operations_admin');
        $this->actingAs($ops)->post($base, ['f' => ['password' => 'x']])->assertForbidden();
    }
}
