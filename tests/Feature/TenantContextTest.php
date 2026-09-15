<?php

namespace Tests\Feature;

use App\Exceptions\TenantContextException;
use App\Models\Company;
use App\Models\ContextSwitchLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\ContextSwitchService;
use App\Services\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Organization $acme;

    private Organization $rakip;

    private Company $acmeLtd;

    private Company $rakipLtd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->context = app(TenantContext::class);

        $this->acme = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->rakip = Organization::create(['name' => 'Rakip', 'slug' => 'rakip']);
        $this->acmeLtd = Company::create(['organization_id' => $this->acme->id, 'legal_name' => 'Acme Ltd']);
        $this->rakipLtd = Company::create(['organization_id' => $this->rakip->id, 'legal_name' => 'Rakip Ltd']);
    }

    private function member(Organization $org, string $status = 'active'): User
    {
        $user = User::factory()->create();

        OrganizationMember::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'status' => $status,
        ]);

        return $user;
    }

    #[Test]
    public function aktif_context_yoksa_409_atilir(): void
    {
        $user = $this->member($this->acme);

        $this->expectException(TenantContextException::class);
        $this->context->requireOrganization($user);
    }

    #[Test]
    public function uyeligi_iptal_edilen_kullanicinin_oturumu_context_kaybeder(): void
    {
        // Oturum açıkken üyelik askıya alınırsa, session'daki org id artık
        // geçerli değildir. Session'a güvenmemenin somut karşılığı bu testtir.
        $user = $this->member($this->acme);
        $this->context->setActiveOrganization($this->acme->id);

        $this->assertSame($this->acme->id, $this->context->requireOrganization($user)->id);

        OrganizationMember::where('user_id', $user->id)->update(['status' => 'suspended']);

        try {
            $this->context->requireOrganization($user);
            $this->fail('Askıya alınmış üyelik için istisna bekleniyordu.');
        } catch (TenantContextException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Session temizlenmiş olmalı.
        $this->assertNull($this->context->activeOrganizationId());
    }

    #[Test]
    public function baska_tenantin_sirketi_404_doner_403_degil(): void
    {
        // 403 "bu kayıt var ama senin değil" bilgisini sızdırır; 404 sızdırmaz.
        $user = $this->member($this->acme);
        $this->context->setActiveOrganization($this->acme->id);

        try {
            $this->context->resolveCompany($user, $this->rakipLtd->id);
            $this->fail('Başka tenant şirketi için istisna bekleniyordu.');
        } catch (TenantContextException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function var_olmayan_sirket_ile_baska_tenantin_sirketi_ayni_cevabi_verir(): void
    {
        $user = $this->member($this->acme);
        $this->context->setActiveOrganization($this->acme->id);

        $codes = [];

        foreach ([$this->rakipLtd->id, 999999] as $companyId) {
            try {
                $this->context->resolveCompany($user, $companyId);
            } catch (TenantContextException $e) {
                $codes[] = $e->getStatusCode();
            }
        }

        $this->assertSame([404, 404], $codes, 'Enumeration savunması: iki durum ayırt edilememeli.');
    }

    #[Test]
    public function context_degistirme_uyelik_ister_ve_loglanir(): void
    {
        $user = $this->member($this->acme);
        OrganizationMember::create([
            'organization_id' => $this->rakip->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $switch = app(ContextSwitchService::class);
        $this->context->setActiveOrganization($this->acme->id);

        $switch->switchTo($user, $this->rakip->id, '203.0.113.7');

        $this->assertSame($this->rakip->id, $this->context->activeOrganizationId());

        $log = ContextSwitchLog::latest('id')->first();
        $this->assertSame($this->acme->id, (int) $log->from_organization_id);
        $this->assertSame($this->rakip->id, (int) $log->to_organization_id);
        $this->assertSame('203.0.113.7', $log->ip_address);
    }

    #[Test]
    public function uyesi_olunmayan_organizasyona_gecilemez(): void
    {
        $user = $this->member($this->acme);
        $switch = app(ContextSwitchService::class);

        $this->expectException(TenantContextException::class);
        $switch->switchTo($user, $this->rakip->id);
    }

    #[Test]
    public function to_array_organizasyonu_daima_dogrulanmis_contextten_alir(): void
    {
        $user = $this->member($this->acme);
        $this->context->setActiveOrganization($this->acme->id);

        $context = $this->context->toArray($user, $this->acmeLtd->id);

        $this->assertSame($this->acme->id, $context['organization_id']);
        $this->assertSame($this->acmeLtd->id, $context['company_id']);
    }
}
