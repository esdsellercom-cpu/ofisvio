<?php

namespace Tests\Feature\Panel;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 7 — şirket üyelikleri: davet, rol, askıya alma, sınırlar.
 */
class MembershipTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Notification::fake();
    }

    #[Test]
    public function sahip_uye_davet_eder_ve_uye_panele_girip_sirketi_gorur(): void
    {
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);

        $this->actingAs($owner)->withContext($acme)
            ->get("/panel/sirketler/{$company->id}/uyeler")
            ->assertOk()
            ->assertSee('Üye davet et')
            ->assertSee('Sahip'); // kendi rolü listede

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/uyeler", [
                'name' => 'Ali Veli',
                'email' => 'Ali@Example.com',
                'role' => 'accountant',
            ])
            ->assertRedirect("/panel/sirketler/{$company->id}/uyeler")
            ->assertSessionHas('status');

        $ali = User::where('email', 'ali@example.com')->firstOrFail();
        Notification::assertSentTo($ali, ResetPassword::class);

        // İki kayıt da yazılmış olmalı: üyelik + şirket kapsamlı rol.
        $this->assertTrue($ali->organizationMemberships()->where('organization_id', $acme->id)->where('status', 'active')->exists());
        $this->assertTrue(UserRole::where('user_id', $ali->id)->where('company_id', $company->id)->exists());

        // Muhasebeci: şirketi görür (company.view), belge yükleyemez (kyc.upload yok).
        $this->actingAs($ali)->get('/panel')->assertOk()->assertSee('Acme Ltd');
        $this->actingAs($ali)->withContext($acme)->get("/panel/sirketler/{$company->id}")->assertOk();
        $this->actingAs($ali)->withContext($acme)->get("/panel/sirketler/{$company->id}/kyc")->assertForbidden();
        $this->actingAs($ali)->withContext($acme)->get("/panel/sirketler/{$company->id}/uyeler")->assertForbidden();
    }

    #[Test]
    public function mevcut_kullaniciya_yalnizca_rol_atanir(): void
    {
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);
        $existing = User::factory()->create(['email' => 'var@example.com']);

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/uyeler", ['name' => 'X', 'email' => 'var@example.com', 'role' => 'viewer'])
            ->assertSessionHasErrors('name'); // min:2

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/uyeler", ['name' => 'Var Olan', 'email' => 'var@example.com', 'role' => 'viewer'])
            ->assertRedirect("/panel/sirketler/{$company->id}/uyeler");

        $this->assertSame(1, User::where('email', 'var@example.com')->count());
        Notification::assertNothingSent();
        $this->assertTrue(UserRole::where('user_id', $existing->id)->where('company_id', $company->id)->exists());
    }

    #[Test]
    public function personel_rolu_sirkete_atanamaz(): void
    {
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);

        $this->actingAs($owner)->withContext($acme)
            ->from("/panel/sirketler/{$company->id}/uyeler")
            ->post("/panel/sirketler/{$company->id}/uyeler", ['name' => 'Sızma', 'email' => 's@example.com', 'role' => 'super_admin'])
            ->assertSessionHasErrors('role');

        $this->assertFalse(User::where('email', 's@example.com')->exists());
    }

    #[Test]
    public function askiya_alinan_uye_yetkisini_kaybeder_ve_geri_alinabilir(): void
    {
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);
        $admin = $this->member($acme);
        $this->grantRole($admin, 'company_admin', ['company_id' => $company->id]);
        $role = UserRole::where('user_id', $admin->id)->where('company_id', $company->id)->firstOrFail();

        $this->actingAs($admin)->withContext($acme)->get("/panel/sirketler/{$company->id}/kyc")->assertOk();

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/uyeler/{$role->id}/askiya-al")
            ->assertRedirect("/panel/sirketler/{$company->id}/uyeler");

        $this->assertSame('suspended', $role->fresh()->status);
        $this->actingAs($admin)->withContext($acme)->get("/panel/sirketler/{$company->id}/kyc")->assertForbidden();

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/uyeler/{$role->id}/etkinlestir")
            ->assertRedirect("/panel/sirketler/{$company->id}/uyeler");

        $this->actingAs($admin)->withContext($acme)->get("/panel/sirketler/{$company->id}/kyc")->assertOk();
    }

    #[Test]
    public function sahip_kendi_rolunu_askiya_alamaz(): void
    {
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);
        $own = UserRole::where('user_id', $owner->id)->where('company_id', $company->id)->firstOrFail();

        $this->actingAs($owner)->withContext($acme)
            ->from("/panel/sirketler/{$company->id}/uyeler")
            ->post("/panel/sirketler/{$company->id}/uyeler/{$own->id}/askiya-al")
            ->assertSessionHasErrors('member');

        $this->assertSame('active', $own->fresh()->status);
    }

    #[Test]
    public function kardes_sirketin_rol_kaydi_bu_sirketin_urlsiyle_degistirilemez(): void
    {
        $acme = $this->organization('Acme');
        $benim = $this->company($acme, 'Benim Ltd');
        $kardes = $this->company($acme, 'Kardeş Ltd');
        $owner = $this->owner($acme, $benim);
        $kardesUye = $this->member($acme);
        $this->grantRole($kardesUye, 'employee', ['company_id' => $kardes->id]);
        $role = UserRole::where('user_id', $kardesUye->id)->firstOrFail();

        // scopeBindings: {userRole} {company}->userRoles() içinde değil -> 404.
        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$benim->id}/uyeler/{$role->id}/askiya-al")
            ->assertNotFound();

        $this->assertSame('active', $role->fresh()->status);
    }
}
