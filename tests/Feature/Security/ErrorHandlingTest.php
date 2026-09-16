<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Panel\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Audit §22-23 — Hata durumları gerçek HTTP kodu + Türkçe hata sayfası;
 * 422 form hatası alanına döner; sahte başarı yok. §28-29 — env tabanlı hesap
 * açılışı: şifre kaynak kodda değil, politika zorunlu, test müşterisi production'da yok.
 */
class ErrorHandlingTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    #[Test]
    public function hata_kodlari_gercek_ve_turkce_sayfa_basar(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WebsiteSeeder::class);

        $this->get('/olmayan-sayfa')->assertNotFound()->assertSee('Sayfa bulunamadı')->assertDontSee('Illuminate');
        $this->get('/panel/icerik')->assertRedirect('/login'); // 401 yerine giriş yönlendirmesi (web guard)

        $owner = $this->owner($this->organization('Acme'));
        $this->actingAs($owner)->get('/panel/icerik')->assertForbidden()->assertSee('Bu işlem için yetkiniz yok');

        // 422: doğrulama hatası forma döner, kayıt oluşmaz, "başarı" mesajı yok.
        $this->from('/')->post('/talep', ['kind' => 'quote', 'name' => '', 'email' => 'bozuk'])
            ->assertRedirect('/')->assertSessionHasErrors(['name', 'email'])->assertSessionMissing('lead_sent');

        // 419 (CSRF) ve 429 (throttle) Laravel middleware'leriyle üretilir; sayfaları errors/419, errors/429.
        $this->assertFileExists(resource_path('views/errors/419.blade.php'));
        $this->assertFileExists(resource_path('views/errors/429.blade.php'));
        $this->assertFileExists(resource_path('views/errors/500.blade.php'));
        $this->assertFileExists(resource_path('views/errors/503.blade.php'));
    }

    #[Test]
    public function env_tabanli_hesap_acilisi_politika_ve_ortam_kurallarina_uyar(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // Zayıf şifre reddedilir; hesap açılmaz.
        config(['ofisvio.accounts.admin_email' => 'root@ofisvio.local', 'ofisvio.accounts.admin_password' => 'short']);
        $this->assertSame(1, Artisan::call('ofisvio:bootstrap-accounts'));
        $this->assertNull(User::where('email', 'root@ofisvio.local')->first());

        // Politikaya uyan şifre: super_admin açılır; şifre hash'lidir, düz metin saklanmaz.
        config(['ofisvio.accounts.admin_password' => 'Guclu-Sifre-2026!Xy']);
        $this->assertSame(0, Artisan::call('ofisvio:bootstrap-accounts'));
        $admin = User::where('email', 'root@ofisvio.local')->firstOrFail();
        $this->assertNotSame('Guclu-Sifre-2026!Xy', $admin->password);
        $this->assertTrue(password_verify('Guclu-Sifre-2026!Xy', $admin->password));
        $this->assertTrue($admin->userRoles()->whereHas('role', fn ($q) => $q->where('name', 'super_admin'))->exists());

        // Test müşterisi production'da açılmaz.
        config(['ofisvio.accounts.test_customer_email' => 'customer@ofisvio.local', 'ofisvio.accounts.test_customer_password' => 'Musteri-Sifre-2026!Ab']);
        config(['app.env' => 'production']);
        $this->assertSame(1, Artisan::call('ofisvio:bootstrap-accounts'));
        $this->assertNull(User::where('email', 'customer@ofisvio.local')->first());

        config(['app.env' => 'testing']);
        $this->assertSame(0, Artisan::call('ofisvio:bootstrap-accounts'));
        $customer = User::where('email', 'customer@ofisvio.local')->firstOrFail();
        $this->assertTrue($customer->userRoles()->whereNotNull('company_id')->whereHas('role', fn ($q) => $q->where('name', 'owner'))->exists());

        // Giriş gerçek: doğru şifreyle oturum açılır, yanlışla açılmaz.
        $this->post('/login', ['email' => 'customer@ofisvio.local', 'password' => 'yanlis'])->assertSessionHasErrors();
        $this->assertGuest();
        $this->post('/login', ['email' => 'customer@ofisvio.local', 'password' => 'Musteri-Sifre-2026!Ab'])->assertRedirect();
        $this->assertAuthenticatedAs($customer);
    }
}
