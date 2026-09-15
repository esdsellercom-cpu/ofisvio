<?php

namespace Tests\Feature\Panel;

use App\Models\ContextSwitchLog;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F2 — organizasyon seçimi: üyelik yolu, personel yolu, otomatik seçim.
 */
class ContextSelectionTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    #[Test]
    public function tek_uyelikli_kullanici_panele_dogrudan_girer(): void
    {
        $acme = $this->organization('Acme');
        $user = $this->member($acme);

        $this->actingAs($user)->get('/panel')
            ->assertOk()
            ->assertSee('Acme')
            ->assertSee('Genel bakış');

        $this->assertSame($acme->id, session(TenantContext::SESSION_KEY));
    }

    #[Test]
    public function cok_uyelikli_kullanici_secim_ekranina_yonlenir(): void
    {
        $acme = $this->organization('Acme');
        $beta = $this->organization('Beta');
        $user = $this->member($acme);
        $this->member($beta, $user);

        $this->actingAs($user)->get('/panel')->assertRedirect('/panel/organizasyon');

        $this->actingAs($user)->get('/panel/organizasyon')
            ->assertOk()
            ->assertSee('Acme')
            ->assertSee('Beta')
            ->assertDontSee('Bekleyen KYC'); // personel sütunu müşteriye görünmez
    }

    #[Test]
    public function uyelik_yolundan_gecis_loglanir_ve_panele_doner(): void
    {
        $acme = $this->organization('Acme');
        $beta = $this->organization('Beta');
        $user = $this->member($acme);
        $this->member($beta, $user);

        $this->actingAs($user)
            ->post('/panel/organizasyon', ['organization_id' => $beta->id])
            ->assertRedirect('/panel');

        $log = ContextSwitchLog::latest('id')->first();
        $this->assertSame($beta->id, (int) $log->to_organization_id);
        $this->assertSame(ContextSwitchLog::PATH_MEMBERSHIP, $log->entry_path);
    }

    #[Test]
    public function uyesi_olunmayan_organizasyona_gecilemez(): void
    {
        $acme = $this->organization('Acme');
        $rakip = $this->organization('Rakip');
        $user = $this->member($acme);

        $this->actingAs($user)
            ->from('/panel/organizasyon')
            ->post('/panel/organizasyon', ['organization_id' => $rakip->id])
            ->assertRedirect('/panel/organizasyon')
            ->assertSessionHasErrors('organization_id');

        $this->assertNull(session(TenantContext::SESSION_KEY));
        $this->assertSame(0, ContextSwitchLog::count());
    }

    #[Test]
    public function personel_hicbir_uyelik_olmadan_her_organizasyona_girer_ve_staff_olarak_loglanir(): void
    {
        $acme = $this->organization('Acme');
        $beta = $this->organization('Beta');
        $admin = $this->staff('system_admin');

        // Üyelik yok -> otomatik seçim yok -> seçim ekranı.
        $this->actingAs($admin)->get('/panel')->assertRedirect('/panel/organizasyon');

        $this->actingAs($admin)->get('/panel/organizasyon')
            ->assertOk()
            ->assertSee('Acme')
            ->assertSee('Beta')
            ->assertSee('Bekleyen KYC')
            ->assertSee('Yeni müşteri organizasyonu');

        $this->actingAs($admin)
            ->post('/panel/organizasyon', ['organization_id' => $beta->id])
            ->assertRedirect('/panel');

        $log = ContextSwitchLog::latest('id')->first();
        $this->assertSame(ContextSwitchLog::PATH_STAFF, $log->entry_path);
        $this->assertSame($admin->id, (int) $log->user_id);

        $this->actingAs($admin)->withContext($beta)->get('/panel')->assertOk()->assertSee('Beta');
    }

    #[Test]
    public function sirkete_scopelanmis_internal_rol_personel_yolunu_acmaz(): void
    {
        // Sehven bir şirkete bağlanmış system_admin ataması global değildir.
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $user = User::factory()->create();
        $this->grantRole($user, 'system_admin', ['company_id' => $company->id]);

        $this->actingAs($user)
            ->from('/panel/organizasyon')
            ->post('/panel/organizasyon', ['organization_id' => $acme->id])
            ->assertSessionHasErrors('organization_id');
    }

    #[Test]
    public function uyeligi_olmayan_musteri_bos_secim_ekrani_gorur(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/panel/organizasyon')
            ->assertOk()
            ->assertSee('henüz bir organizasyona bağlı değil');
    }

    #[Test]
    public function uyeligi_dusen_kullanici_secim_ekranina_atilir(): void
    {
        $acme = $this->organization('Acme');
        $user = $this->member($acme);

        $this->actingAs($user)->withContext($acme)->get('/panel')->assertOk();

        $user->organizationMemberships()->update(['status' => 'suspended']);

        // requireOrganization 403 atar; tarayıcı için bu seçim ekranıdır.
        $this->actingAs($user)->withContext($acme)->get('/panel')
            ->assertRedirect('/panel/organizasyon');
    }
}
