<?php

namespace Tests\Feature\Console;

use App\Models\NotificationLog;
use App\Models\NotificationRecipient;
use Database\Seeders\NotificationRuleSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Panel\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Audit F-18: doctor hatası / failed_jobs → system.alert bildirimi (süper yöneticiler); 6 saat içinde aynı kontrol
 * tekrar uyarmaz; düzelince ve süre dolunca yeniden uyarır; sağlıklı sistemde bildirim yok.
 */
class HealthAlertTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    #[Test]
    public function doctor_hatasi_ve_basarisiz_isler_yoneticiye_bir_kez_bildirilir(): void
    {
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->seed(NotificationRuleSeeder::class);
        $admin = $this->staff('super_admin');
        NotificationRecipient::create(['name' => 'Süper yönetici', 'channel' => 'in_app', 'user_id' => $admin->id, 'group' => 'super_admin', 'is_active' => true]);
        NotificationRecipient::create(['name' => 'Ops posta', 'channel' => 'email', 'address' => 'ops@example.com', 'group' => 'super_admin', 'is_active' => true]);

        // Sağlıklı (geliştirme ortamı: uyarılar hata değil) → bildirim yok.
        $this->artisan('ofisvio:health-alert')->expectsOutputToContain('Sistem sağlıklı')->assertSuccessful();
        $this->assertSame(0, NotificationLog::query()->where('event', 'system.alert')->count());

        // failed_jobs birikti → uyarı (uygulama içi + e-posta kuralı); aynı hata 6 saat içinde ikinci kez bildirilmez.
        DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        $this->artisan('ofisvio:health-alert')->expectsOutputToContain('1 yeni sistem uyarısı')->assertSuccessful();
        $logs = NotificationLog::query()->where('event', 'system.alert')->get();
        $this->assertGreaterThanOrEqual(1, $logs->count());
        $this->assertStringContainsString('Başarısız kuyruk işleri', (string) $logs->first()->body);
        $this->assertStringContainsString('1 iş failed_jobs', (string) $logs->first()->body);
        $this->artisan('ofisvio:health-alert')->expectsOutputToContain('1 hata sürüyor; yeni uyarı yok')->assertSuccessful();
        $this->assertSame($logs->count(), NotificationLog::query()->where('event', 'system.alert')->count());

        // Süre dolunca (damga düşünce) yeniden uyarır; iş temizlenince sağlıklı.
        Cache::flush();
        $this->artisan('ofisvio:health-alert')->expectsOutputToContain('1 yeni sistem uyarısı');
        DB::table('failed_jobs')->delete();
        $this->artisan('ofisvio:health-alert')->expectsOutputToContain('Sistem sağlıklı');

        // Yönetici uygulama içi bildirimi görür; doctor "Başarısız işler" satırı basar.
        $this->actingAs($admin)->get('/panel/bildirimler')->assertOk();
        $this->artisan('ofisvio:doctor')->expectsOutputToContain('Başarısız işler');
    }
}
