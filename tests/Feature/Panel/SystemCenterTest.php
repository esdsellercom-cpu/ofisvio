<?php

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\IntegrationLog;
use Database\Seeders\WebsiteSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Üretim denetimi (faz 52): API & Entegrasyonlar merkezi (secret'lar maskeli, env adı görünür, değer yok; test
 * bağlantısı gerçek yoklama + günlük + audit) ve Sistem sağlığı (doctor kontrolleri). Yetki: görüntüleme settings.view,
 * test settings.manage. Yakalanmayan DomainException forma geri döner (500 yok).
 */
class SystemCenterTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    #[Test]
    public function api_merkezi_maskeli_gosterir_baglanti_testi_yapar_ve_saglik_sayfasi_doctor_kontrollerini_basar(): void
    {
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        config(['integrations.providers.sms.enabled' => true, 'integrations.providers.sms.base_url' => 'https://sms.example', 'integrations.providers.sms.secrets.api_key' => 'GIZLI-ANAHTAR-123456']);
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // settings.view var, manage yok
        $finance = $this->staff('finance_admin'); // settings yok

        $page = $this->actingAs($admin)->get('/panel/ayarlar/api')->assertOk();
        $page->assertSee('API &amp; Entegrasyonlar', false)->assertSee('SMS')->assertSee('WhatsApp')->assertSee('iyzico')->assertSee('IndexNow')->assertSee('E-posta')->assertSee('Veritabanı')->assertSee('Kuyruk')->assertSee('SMS_API_KEY')->assertSee('Bağlantıyı test et')
            ->assertDontSee('GIZLI-ANAHTAR-123456')->assertDontSee('Mapbox API')->assertSee('Kullanılmayan servisler');
        $this->actingAs($ops)->get('/panel/ayarlar/api')->assertOk()->assertDontSee('Bağlantıyı test et');
        $this->actingAs($finance)->get('/panel/ayarlar/api')->assertForbidden();

        // Test bağlantısı: çekirdek (veritabanı) ok; kapalı sağlayıcı uyarı; günlük + audit; yetkisiz 403; tanımsız anahtar hata.
        $this->actingAs($admin)->post('/panel/ayarlar/api/database/test')->assertRedirect('/panel/ayarlar/api');
        $this->actingAs($admin)->get('/panel/ayarlar/api')->assertOk()->assertSee('çalışıyor');
        $this->assertTrue(IntegrationLog::query()->where('provider', 'health:database')->where('ok', true)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'integration.tested')->exists());
        $this->actingAs($admin)->post('/panel/ayarlar/api/whatsapp/test')->assertRedirect();
        $this->assertTrue(IntegrationLog::query()->where('provider', 'health:whatsapp')->where('ok', false)->exists());
        $this->actingAs($admin)->post('/panel/ayarlar/api/bilinmeyen/test')->assertRedirect();
        $this->assertStringContainsString('Tanımsız', (string) IntegrationLog::query()->where('provider', 'health:bilinmeyen')->value('error'));
        $this->actingAs($ops)->post('/panel/ayarlar/api/database/test')->assertForbidden();

        // Sağlık: doctor satırları, durum etiketleri, son testler; yeniden kontrol audit'e düşer.
        $health = $this->actingAs($admin)->get('/panel/ayarlar/saglik')->assertOk();
        $health->assertSee('Sistem sağlığı')->assertSee('APP_KEY')->assertSee('Veritabanı')->assertSee('Migrasyonlar')->assertSee('Zamanlayıcı')->assertSee('Çalışıyor')->assertSee('database');
        $this->actingAs($admin)->post('/panel/ayarlar/saglik')->assertRedirect('/panel/ayarlar/saglik');
        $this->assertTrue(AuditLog::query()->where('action', 'system.health_checked')->exists());
        $this->actingAs($finance)->get('/panel/ayarlar/saglik')->assertForbidden();
    }

    #[Test]
    public function yakalanmayan_is_kurali_hatasi_500_yerine_forma_doner(): void
    {
        // Güvenlik ağı (bootstrap/app.php): controller yakalamasa bile DomainException kullanıcıya stack trace değil,
        // forma dönen anlaşılır bir hata mesajıdır; JSON isteyene 422. Geçici test rotası yalnız bu test sürecinde tanımlıdır.
        Route::middleware('web')->post('/panel/_denetim-kural', fn () => throw new DomainException('Bu işlem iş kuralına aykırı.'));
        Route::middleware('web')->get('/panel/_denetim-kural-get', fn () => throw new DomainException('GET kuralı'));

        $this->from('/panel/geri')->post('/panel/_denetim-kural', ['x' => '1'])->assertRedirect('/panel/geri')->assertSessionHasErrors('domain');
        $this->postJson('/panel/_denetim-kural')->assertStatus(422)->assertJson(['message' => 'Bu işlem iş kuralına aykırı.']);
        $this->get('/panel/_denetim-kural-get')->assertStatus(500); // GET'te geri dönecek form yok: genel hata sayfası (ayrıntı log'da)
    }
}
