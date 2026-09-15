<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AuthorizationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RBAC + Scope çekirdek testleri.
 *
 * Bu testlerin çoğu "izin verilmemeli" senaryosudur. Yetkilendirme kodunda
 * asıl risk, izin vermeyi unutmak değil — fazladan izin vermektir; bir
 * regresyon testinin değeri de oradadır.
 */
class AuthorizationScopeTest extends TestCase
{
    use RefreshDatabase;

    private AuthorizationService $auth;

    private Organization $acme;

    private Organization $rakip;

    private Company $acmeLtd;

    private Company $acmeIkinci;

    private Company $rakipLtd;

    private Location $konya;

    private Location $ankara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->auth = app(AuthorizationService::class);

        $this->acme = Organization::create(['name' => 'Acme Holding', 'slug' => 'acme']);
        $this->rakip = Organization::create(['name' => 'Rakip AŞ', 'slug' => 'rakip']);

        $this->acmeLtd = Company::create(['organization_id' => $this->acme->id, 'legal_name' => 'Acme Ltd']);
        $this->acmeIkinci = Company::create(['organization_id' => $this->acme->id, 'legal_name' => 'Acme İkinci Ltd']);
        $this->rakipLtd = Company::create(['organization_id' => $this->rakip->id, 'legal_name' => 'Rakip Ltd']);

        $this->konya = Location::create(['name' => 'Konya', 'slug' => 'konya']);
        $this->ankara = Location::create(['name' => 'Ankara', 'slug' => 'ankara']);
    }

    private function userWithRole(string $roleName, array $scope = []): User
    {
        $user = User::factory()->create();

        UserRole::create([
            'user_id' => $user->id,
            'role_id' => Role::where('name', $roleName)->value('id'),
            'company_id' => $scope['company_id'] ?? null,
            'organization_id' => $scope['organization_id'] ?? null,
            'location_id' => $scope['location_id'] ?? null,
            'status' => $scope['status'] ?? 'active',
        ]);

        return $user;
    }

    // ---------------------------------------------------------------
    // Company kapsamı
    // ---------------------------------------------------------------

    #[Test]
    public function sirket_sahibi_kendi_sirketinin_kyc_belgesini_gorebilir(): void
    {
        $owner = $this->userWithRole('owner', ['company_id' => $this->acmeLtd->id]);

        $this->assertTrue($this->auth->can($owner, 'kyc.view', ['company_id' => $this->acmeLtd->id]));
    }

    #[Test]
    public function sirket_sahibi_baska_organizasyondaki_sirketi_goremez(): void
    {
        $owner = $this->userWithRole('owner', ['company_id' => $this->acmeLtd->id]);

        $this->assertFalse($this->auth->can($owner, 'kyc.view', ['company_id' => $this->rakipLtd->id]));
    }

    #[Test]
    public function sirket_sahibi_ayni_organizasyondaki_diger_sirketi_bile_goremez(): void
    {
        // Yatay yetki yükseltmenin en sinsi hali: aynı holding altındaki
        // kardeş şirket. Rol company kapsamlı olduğu için erişim OLMAMALI.
        $owner = $this->userWithRole('owner', ['company_id' => $this->acmeLtd->id]);

        $this->assertFalse($this->auth->can($owner, 'kyc.view', ['company_id' => $this->acmeIkinci->id]));
    }

    #[Test]
    public function context_verilmezse_company_kapsamli_izin_calismaz(): void
    {
        // Controller context geçirmeyi unutursa sonuç "herkese açık" değil,
        // "reddedildi" olmalı (fail-closed).
        $owner = $this->userWithRole('owner', ['company_id' => $this->acmeLtd->id]);

        $this->assertFalse($this->auth->can($owner, 'kyc.view'));
    }

    // ---------------------------------------------------------------
    // Global kapsam — eski sürümdeki kritik hatanın regresyon testi
    // ---------------------------------------------------------------

    #[Test]
    public function global_rol_company_contextinde_de_yetkilidir(): void
    {
        // REGRESYON: Eski AuthorizationService role_permissions.scope'u hiç
        // okumadığı için, user_roles kaydı NULL olan global admin company
        // context'i geçen HER kontrolde false alıyordu.
        $admin = $this->userWithRole('system_admin');

        $this->assertTrue($this->auth->can($admin, 'kyc.approve', ['company_id' => $this->acmeLtd->id]));
        $this->assertTrue($this->auth->can($admin, 'kyc.approve', ['location_id' => $this->konya->id]));
        $this->assertTrue($this->auth->can($admin, 'kyc.approve'));
    }

    #[Test]
    public function sirkete_baglanmis_global_rol_global_yetki_kazanmaz(): void
    {
        // REGRESYON: yanlışlıkla bir şirkete scope'lanmış super_admin ataması
        // global yetkiye dönüşmemeli. Eski sürüm bu kaydı company context'inde
        // kabul ediyordu.
        $misassigned = $this->userWithRole('super_admin', ['company_id' => $this->acmeLtd->id]);

        $this->assertFalse($this->auth->can($misassigned, 'break_glass.activate'));
        $this->assertFalse($this->auth->can($misassigned, 'kyc.approve', ['company_id' => $this->acmeLtd->id]));
    }

    #[Test]
    public function musteri_rolu_global_kontrolu_karsilayamaz(): void
    {
        $owner = $this->userWithRole('owner', ['company_id' => $this->acmeLtd->id]);

        $this->assertFalse($this->auth->can($owner, 'admin.panel.access'));
        $this->assertFalse($this->auth->can($owner, 'kyc.approve', ['company_id' => $this->acmeLtd->id]));
    }

    // ---------------------------------------------------------------
    // Location kapsamı
    // ---------------------------------------------------------------

    #[Test]
    public function resepsiyon_yalnizca_kendi_lokasyonunu_gorur(): void
    {
        $reception = $this->userWithRole('reception', ['location_id' => $this->konya->id]);

        $this->assertTrue($this->auth->can($reception, 'visitor.view', ['location_id' => $this->konya->id]));
        $this->assertFalse($this->auth->can($reception, 'visitor.view', ['location_id' => $this->ankara->id]));
        $this->assertFalse($this->auth->can($reception, 'visitor.view'));
    }

    // ---------------------------------------------------------------
    // Organization kapsamı ve kapsam içerme
    // ---------------------------------------------------------------

    #[Test]
    public function organizasyon_kapsamli_izin_alt_sirket_contextinden_de_cozulur(): void
    {
        // organization.view owner rolünde organization kapsamlı. Context yalnızca
        // company_id verse bile, şirketin bağlı olduğu organizasyona genişletilir.
        $owner = $this->userWithRole('owner', ['organization_id' => $this->acme->id]);

        $this->assertTrue($this->auth->can($owner, 'organization.view', ['organization_id' => $this->acme->id]));
        $this->assertTrue($this->auth->can($owner, 'organization.view', ['company_id' => $this->acmeLtd->id]));
        $this->assertFalse($this->auth->can($owner, 'organization.view', ['company_id' => $this->rakipLtd->id]));
    }

    #[Test]
    public function company_kapsamli_rol_organizasyon_kontrolunu_karsilayamaz(): void
    {
        // Genişleme tek yönlüdür: company -> organization DEĞİL.
        $owner = $this->userWithRole('owner', ['company_id' => $this->acmeLtd->id]);

        $this->assertFalse($this->auth->can($owner, 'organization.view', ['organization_id' => $this->acme->id]));
    }

    // ---------------------------------------------------------------
    // Durum ve fail-closed davranışı
    // ---------------------------------------------------------------

    #[Test]
    public function askiya_alinmis_rol_yetki_vermez(): void
    {
        $owner = $this->userWithRole('owner', [
            'company_id' => $this->acmeLtd->id,
            'status' => 'suspended',
        ]);

        $this->assertFalse($this->auth->can($owner, 'kyc.view', ['company_id' => $this->acmeLtd->id]));
    }

    #[Test]
    public function tanimsiz_izin_adi_reddedilir(): void
    {
        $admin = $this->userWithRole('super_admin');

        // Yazım hatası olan bir izin adı, super_admin için bile reddedilmeli.
        $this->assertFalse($this->auth->can($admin, 'kyc.aprove'));
        $this->assertFalse($this->auth->can($admin, ''));
    }

    #[Test]
    public function hicbir_rolu_olmayan_kullanici_hicbir_sey_yapamaz(): void
    {
        $nobody = User::factory()->create();

        $this->assertFalse($this->auth->can($nobody, 'kyc.view', ['company_id' => $this->acmeLtd->id]));
        $this->assertFalse($this->auth->can($nobody, 'admin.panel.access'));
    }

    // ---------------------------------------------------------------
    // JIT
    // ---------------------------------------------------------------

    #[Test]
    public function jit_gerektiren_izin_isaretlenir(): void
    {
        $admin = $this->userWithRole('system_admin');

        // kyc.view_status JIT gerektirmez, kyc.view_document gerektirir.
        $this->assertFalse($this->auth->requiresJit($admin, 'kyc.view_status'));
        $this->assertTrue($this->auth->requiresJit($admin, 'kyc.view_document'));
    }

    #[Test]
    public function jitsiz_yol_varsa_jit_gerekmez(): void
    {
        // REGRESYON: eski requiresJit(), izni taşıyan HERHANGİ bir rolde
        // requires_jit varsa true dönüyordu. Kullanıcının JIT'siz bir yolu
        // varsa o yol kazanmalı.
        $user = User::factory()->create();

        foreach ([['system_admin', null], ['owner', $this->acmeLtd->id]] as [$role, $companyId]) {
            UserRole::create([
                'user_id' => $user->id,
                'role_id' => Role::where('name', $role)->value('id'),
                'company_id' => $companyId,
            ]);
        }

        // contract.view owner için JIT'siz; system_admin global yoldan da erişir.
        $this->assertTrue($this->auth->can($user, 'contract.view', ['company_id' => $this->acmeLtd->id]));
        $this->assertFalse($this->auth->requiresJit($user, 'contract.view', ['company_id' => $this->acmeLtd->id]));
    }

    #[Test]
    public function yetkisi_olmayan_kullanici_icin_requires_jit_fail_closed_doner(): void
    {
        // Erişimi olmayan biri için "JIT gerekmiyor" demek, çağıran kodun
        // kapıyı açması anlamına gelirdi. Reddedilen karar daima true döner.
        $nobody = User::factory()->create();

        $this->assertTrue($this->auth->requiresJit($nobody, 'kyc.view_document'));
        $this->assertTrue($this->auth->requiresJit($nobody, 'tanimsiz.izin'));
    }
}
