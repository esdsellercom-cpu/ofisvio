<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Location;
use App\Models\LoginEvent;
use App\Models\Space;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Panel\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Çekirdek kimlik/yetki (audit: giriş geçmişi, aktif oturumlar, hesap durumu, lokasyon bazlı erişim,
 * kütle atama / yetki atlatma). Mevcut Fortify/2FA testleri AuthTest ve AccountSecurityTest'te.
 */
class AuthCoreTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    #[Test]
    public function giris_gecmisi_basarili_basarisiz_ve_cikisi_kaydeder(): void
    {
        $user = User::factory()->create(['password' => 'gizli-sifre-123']);

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'yanlis'])->assertSessionHasErrors();
        $this->post('/login', ['email' => $user->email, 'password' => 'gizli-sifre-123'])->assertRedirect('/panel/baslangic');
        $this->post('/logout')->assertRedirect();

        $events = LoginEvent::query()->orderBy('id')->pluck('event')->all();
        $this->assertSame(['failed', 'login', 'logout'], $events);
        $this->assertSame($user->id, LoginEvent::query()->where('event', 'login')->value('user_id'));
        $this->assertSame($user->email, LoginEvent::query()->where('event', 'failed')->value('email'));

        // Kullanıcı kendi geçmişini hesap sayfasında görür; yönetici kullanıcı detayında görür.
        $this->actingAs($user)->get('/panel/hesap')->assertOk()->assertSee('Son girişler')->assertSee('Başarısız deneme')->assertSee('Çıkış');
        $this->actingAs($this->staff('system_admin'))->get("/panel/kullanicilar/{$user->id}")->assertOk()->assertSee('Giriş geçmişi')->assertSee('Başarısız deneme');
    }

    #[Test]
    public function aktif_oturumlar_listelenir_ve_diger_cihazlar_kapatilir(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['password' => 'gizli-sifre-123']);
        $now = time();

        // Başka bir cihazın oturumu (veritabanı satırı) ve bu cihazın oturumu.
        DB::table('sessions')->insert(['id' => 'diger-cihaz', 'user_id' => $user->id, 'ip_address' => '10.0.0.9', 'user_agent' => 'Mozilla/5.0 (Android)', 'payload' => base64_encode(serialize([])), 'last_activity' => $now - 60]);
        DB::table('sessions')->insert(['id' => 'baska-kullanici', 'user_id' => $user->id + 1000, 'ip_address' => '10.0.0.1', 'user_agent' => 'x', 'payload' => base64_encode(serialize([])), 'last_activity' => $now]);

        $this->actingAs($user)->get('/panel/hesap')->assertOk()->assertSee('Aktif oturumlar')->assertSee('10.0.0.9')->assertDontSee('10.0.0.1')->assertSee('Diğer cihazlardan çıkış');

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post('/panel/hesap/oturumlar/kapat')->assertRedirect('/panel/hesap');

        $this->assertDatabaseMissing('sessions', ['id' => 'diger-cihaz']);
        $this->assertDatabaseHas('sessions', ['id' => 'baska-kullanici']); // başkasının oturumuna dokunulmaz
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.other_devices_logout', 'entity_id' => $user->id]);
    }

    #[Test]
    public function askiya_alinan_hesap_giremez_acik_oturumu_duser_ve_yeniden_etkinlesir(): void
    {
        $admin = $this->staff('system_admin');
        $target = User::factory()->create(['password' => 'gizli-sifre-123']);

        // Kendini askıya alamaz; gerekçe zorunlu.
        $this->actingAs($admin)->from("/panel/kullanicilar/{$admin->id}")->post("/panel/kullanicilar/{$admin->id}/askiya-al", ['reason' => 'deneme gerekçesi'])->assertSessionHasErrors('status');
        $this->actingAs($admin)->from("/panel/kullanicilar/{$target->id}")->post("/panel/kullanicilar/{$target->id}/askiya-al", ['reason' => 'kısa'])->assertSessionHasErrors('reason');

        $this->actingAs($admin)->post("/panel/kullanicilar/{$target->id}/askiya-al", ['reason' => 'Sözleşme ihlali şüphesi'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($target->fresh()->isSuspended());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.suspended', 'entity_id' => $target->id, 'actor_id' => $admin->id]);

        // Giriş reddi: genel hata (hesap durumu sızdırılmaz), oturum yok. (login rotası guest-only → önce yöneticiden çık)
        auth()->logout();
        $this->flushSession();
        $this->from('/login')->post('/login', ['email' => $target->email, 'password' => 'gizli-sifre-123'])->assertRedirect('/login')->assertSessionHasErrors(['email' => 'E-posta veya şifre hatalı.']);
        $this->assertGuest();

        // Açık oturumla gelen askıdaki kullanıcı account.active tarafından düşürülür.
        $this->actingAs($target->fresh())->get('/panel/hesap')->assertRedirect('/login');
        $this->assertGuest();

        // Yeniden etkinleştirme → giriş yeniden mümkün.
        $this->flushSession();
        $this->actingAs($admin)->post("/panel/kullanicilar/{$target->id}/etkinlestir")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($target->fresh()->isSuspended());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.reactivated', 'entity_id' => $target->id]);
        auth()->logout();
        $this->flushSession();
        $this->post('/login', ['email' => $target->email, 'password' => 'gizli-sifre-123'])->assertRedirect('/panel/baslangic');
        auth()->logout();
        $this->flushSession();

        // Yönetici oturum kapatma: audit + remember token döner; user.manage olmayan personel 403.
        $before = $target->fresh()->remember_token;
        $this->actingAs($admin)->post("/panel/kullanicilar/{$target->id}/oturumlari-kapat")->assertRedirect();
        $this->assertNotSame($before, $target->fresh()->remember_token);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.sessions_terminated', 'entity_id' => $target->id]);
        $this->actingAs($this->staff('finance_admin'))->post("/panel/kullanicilar/{$target->id}/askiya-al", ['reason' => 'yetkisiz deneme'])->assertForbidden();
    }

    #[Test]
    public function lokasyon_yoneticisi_yalniz_kendi_lokasyonunun_alanlarini_gorur(): void
    {
        $kadikoy = Location::create(['name' => 'Kadıköy', 'slug' => 'kadikoy', 'city' => 'İstanbul', 'is_active' => true]);
        $ankara = Location::create(['name' => 'Ankara', 'slug' => 'ankara', 'city' => 'Ankara', 'is_active' => true]);
        $deskK = Space::create(['location_id' => $kadikoy->id, 'kind' => 'desk_fixed', 'name' => 'K-1', 'capacity' => 1, 'monthly_price' => 300000, 'is_active' => true]);
        $deskA = Space::create(['location_id' => $ankara->id, 'kind' => 'desk_fixed', 'name' => 'ANK-7', 'capacity' => 1, 'monthly_price' => 250000, 'is_active' => true]);

        $manager = $this->staff('location_manager');
        // staff() global atar; lokasyon kapsamlı rol yalnız Kadıköy'de.
        DB::table('user_roles')->where('user_id', $manager->id)->update(['location_id' => $kadikoy->id]);
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');

        $auth = app(AuthorizationService::class);
        $this->assertSame([$kadikoy->id], $auth->locationIdsWith($manager, 'space.view'));
        $this->assertNull($auth->locationIdsWith($this->staff('operations_admin'), 'space.view'));
        $this->assertSame([], $auth->locationIdsWith($this->staff('finance_admin'), 'space.manage'));

        // Liste: yalnız Kadıköy; menüde öge var; kapsam notu görünür.
        $this->actingAs($manager)->get('/panel/alanlar')->assertOk()->assertSee('K-1')->assertDontSee('ANK-7')->assertDontSee('Ankara')->assertSee('Yalnız yetkili olduğunuz lokasyon')->assertSee('Masalar, ofisler &amp; odalar', false);
        // Detay: kendi lokasyonu açılır, yabancı alan 404 (varlık sızdırılmaz).
        $this->actingAs($manager)->get("/panel/alanlar/{$deskK->id}")->assertOk()->assertSee('K-1');
        $this->actingAs($manager)->get("/panel/alanlar/{$deskA->id}")->assertNotFound();
        // Tahsis: yabancı lokasyonda 404, kendi lokasyonunda geçer.
        $this->actingAs($manager)->post("/panel/alanlar/{$deskA->id}/tahsis", ['company_id' => $acmeCo->id, 'starts_on' => '2026-09-17'])->assertNotFound();
        $this->actingAs($manager)->post("/panel/alanlar/{$deskK->id}/tahsis", ['company_id' => $acmeCo->id, 'starts_on' => '2026-09-17'])->assertRedirect("/panel/alanlar/{$deskK->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseHas('space_assignments', ['space_id' => $deskK->id, 'company_id' => $acmeCo->id, 'status' => 'active']);

        // Global personel her şeyi görür; hiç lokasyon izni olmayan personel (finans space.view global taşır) da liste görür ama manage yok.
        $this->actingAs($this->staff('operations_admin'))->get('/panel/alanlar')->assertOk()->assertSee('K-1')->assertSee('ANK-7')->assertDontSee('Yalnız yetkili olduğunuz lokasyon');
        $this->actingAs($this->staff('finance_admin'))->post("/panel/alanlar/{$deskK->id}/tahsis", ['company_id' => $acmeCo->id, 'starts_on' => '2026-09-17'])->assertForbidden();
    }

    #[Test]
    public function kutle_atama_ile_durum_ve_dogrulama_degistirilemez(): void
    {
        // Profil güncellemesi status/email_verified_at/ui_theme gibi alanları kabul etmez (Fillable dışı).
        $user = User::factory()->create();
        $this->actingAs($user)->from('/panel/hesap')->put('/user/profile-information', ['name' => 'Yeni Ad', 'email' => $user->email, 'status' => 'suspended', 'email_verified_at' => null])->assertRedirect('/panel/hesap');
        $fresh = $user->fresh();
        $this->assertSame('Yeni Ad', $fresh->name);
        $this->assertSame('active', $fresh->status);
        $this->assertNotNull($fresh->email_verified_at);

        // Denetim kaydı da salt eklemeli: audit log düzenleme rotası yok.
        $this->assertNull(app('router')->getRoutes()->getByName('panel.audit.update'));
        $this->assertGreaterThanOrEqual(0, AuditLog::query()->count());
    }
}
