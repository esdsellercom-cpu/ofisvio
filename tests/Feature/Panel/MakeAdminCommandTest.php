<?php

namespace Tests\Feature\Panel;

use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MakeAdminCommandTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    #[Test]
    public function ilk_personel_hesabi_global_rolle_olusur(): void
    {
        $this->artisan('ofisvio:make-admin', ['email' => 'Admin@Ofisvio.example', '--name' => 'İlk Yönetici'])
            ->expectsQuestion('Şifre (en az 12 karakter)', 'cok-gizli-sifre-123')
            ->assertSuccessful();

        $user = User::where('email', 'admin@ofisvio.example')->firstOrFail();

        $this->assertTrue(app(TenantContext::class)->isInternalStaff($user));
        $this->assertTrue(password_verify('cok-gizli-sifre-123', $user->password));
    }

    #[Test]
    public function mevcut_hesaba_yalnizca_rol_atanir(): void
    {
        $user = User::factory()->create(['email' => 'var@example.com']);

        $this->artisan('ofisvio:make-admin', ['email' => 'var@example.com', '--role' => 'system_admin'])
            ->assertSuccessful();

        $this->assertSame(1, User::where('email', 'var@example.com')->count());
        $this->assertTrue(app(TenantContext::class)->isInternalStaff($user));
    }

    #[Test]
    public function musteri_rolu_verilemez(): void
    {
        $this->artisan('ofisvio:make-admin', ['email' => 'x@example.com', '--role' => 'owner'])
            ->assertFailed();

        $this->assertFalse(User::where('email', 'x@example.com')->exists());
    }
}
