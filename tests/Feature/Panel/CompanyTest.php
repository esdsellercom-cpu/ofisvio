<?php

namespace Tests\Feature\Panel;

use App\Models\Company;
use App\Models\UserRole;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F3 — şirketler: oluşturma, görüntüleme, kardeş şirket ve yabancı tenant sınırı.
 */
class CompanyTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    #[Test]
    public function organizasyon_sahibi_sirket_acar_ve_sirket_sahibi_olur(): void
    {
        $acme = $this->organization('Acme');
        $owner = $this->owner($acme);

        $this->actingAs($owner)->withContext($acme)
            ->get('/panel/sirketler/yeni')->assertOk()->assertSee('Şirket unvanı');

        $response = $this->actingAs($owner)->withContext($acme)
            ->post('/panel/sirketler', ['legal_name' => 'Acme Yazılım Ltd. Şti.', 'tax_number' => '1234567890']);

        $company = app(TenantContext::class)->runAsSystem(fn () => Company::where('legal_name', 'Acme Yazılım Ltd. Şti.')->firstOrFail());

        $response->assertRedirect('/panel/sirketler/'.$company->id);
        $this->assertSame($acme->id, (int) $company->organization_id);
        $this->assertSame('REGISTERED', $company->status->value);

        // Şirket kapsamlı owner ataması olmadan açan kişi belge yükleyemezdi.
        $this->assertTrue(
            UserRole::where('user_id', $owner->id)->where('company_id', $company->id)->exists()
        );

        $this->actingAs($owner)->withContext($acme)
            ->get('/panel/sirketler/'.$company->id)
            ->assertOk()
            ->assertSee('Acme Yazılım Ltd. Şti.')
            ->assertSee('Kayıt alındı');
    }

    #[Test]
    public function gecersiz_vergi_numarasi_reddedilir(): void
    {
        $acme = $this->organization('Acme');
        $owner = $this->owner($acme);

        $this->actingAs($owner)->withContext($acme)
            ->from('/panel/sirketler/yeni')
            ->post('/panel/sirketler', ['legal_name' => 'X Ltd', 'tax_number' => '12AB'])
            ->assertRedirect('/panel/sirketler/yeni')
            ->assertSessionHasErrors('tax_number');
    }

    #[Test]
    public function sirket_acmak_organization_manage_ister(): void
    {
        $acme = $this->organization('Acme');
        $employee = $this->member($acme); // üye ama organization.manage yok

        $this->actingAs($employee)->withContext($acme)->get('/panel/sirketler/yeni')->assertForbidden();
        $this->actingAs($employee)->withContext($acme)->post('/panel/sirketler', ['legal_name' => 'Kaçak Ltd'])->assertForbidden();
    }

    #[Test]
    public function kardes_sirket_listede_gorunmez_ve_acilamaz(): void
    {
        // Aynı organizasyon, iki şirket; kullanıcı yalnızca birinin sahibi.
        $acme = $this->organization('Acme');
        $benim = $this->company($acme, 'Benim Ltd');
        $kardes = $this->company($acme, 'Kardeş Ltd');
        $owner = $this->owner($acme, $benim);

        $this->actingAs($owner)->withContext($acme)->get('/panel/sirketler')
            ->assertOk()
            ->assertSee('Benim Ltd')
            ->assertDontSee('Kardeş Ltd');

        // Tenant scope organizasyon sınırını çizer; şirket sınırını yetki çizer: 403.
        $this->actingAs($owner)->withContext($acme)->get('/panel/sirketler/'.$kardes->id)->assertForbidden();
    }

    #[Test]
    public function baska_organizasyonun_sirketi_404_doner(): void
    {
        $acme = $this->organization('Acme');
        $rakip = $this->organization('Rakip');
        $rakipLtd = $this->company($rakip, 'Rakip Ltd');
        $owner = $this->owner($acme);

        // 403 değil 404: kaydın varlığı sızdırılmaz.
        $this->actingAs($owner)->withContext($acme)->get('/panel/sirketler/'.$rakipLtd->id)->assertNotFound();
        $this->actingAs($owner)->withContext($acme)->get('/panel/sirketler/999999')->assertNotFound();
    }

    #[Test]
    public function personel_organizasyondaki_tum_sirketleri_gorur(): void
    {
        $acme = $this->organization('Acme');
        $this->company($acme, 'Bir Ltd');
        $this->company($acme, 'İki Ltd');
        $admin = $this->staff();

        $this->actingAs($admin)->withContext($acme)->get('/panel/sirketler')
            ->assertOk()
            ->assertSee('Bir Ltd')
            ->assertSee('İki Ltd')
            ->assertDontSee('Yeni şirket'); // organization.manage personelde yok
    }

    #[Test]
    public function sirket_ve_organizasyon_kunyesi_yetkiyle_duzenlenir(): void
    {
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $company);

        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$company->id}")->assertOk()->assertSee('Künyeyi kaydet');
        $this->actingAs($owner)->withContext($acme)->put("/panel/sirketler/{$company->id}", ['legal_name' => 'Acme Teknoloji A.Ş.', 'tax_number' => '1234567890'])->assertRedirect("/panel/sirketler/{$company->id}");
        $company->refresh();
        $this->assertSame('Acme Teknoloji A.Ş.', $company->legal_name);
        $this->assertSame('1234567890', $company->tax_number);
        $this->actingAs($owner)->withContext($acme)->from("/panel/sirketler/{$company->id}")->put("/panel/sirketler/{$company->id}", ['legal_name' => 'X', 'tax_number' => 'abc'])->assertSessionHasErrors(['legal_name', 'tax_number']);

        // viewer: company.update yok -> 403; başka organizasyonun şirketi -> 404.
        $viewer = $this->member($acme);
        $this->grantRole($viewer, 'viewer', ['company_id' => $company->id]);
        $this->actingAs($viewer)->withContext($acme)->put("/panel/sirketler/{$company->id}", ['legal_name' => 'Ele geçirildi'])->assertForbidden();
        $beta = $this->organization('Beta');
        $betaCo = $this->company($beta, 'Beta Ltd.');
        $this->actingAs($owner)->withContext($acme)->put("/panel/sirketler/{$betaCo->id}", ['legal_name' => 'Ele geçirildi'])->assertNotFound();
        $this->assertSame('Beta Ltd.', $betaCo->fresh()->legal_name);

        // Organizasyon adı (organization.manage); slug sabit.
        $this->actingAs($owner)->withContext($acme)->get('/panel')->assertOk()->assertSee('Organizasyon künyesi');
        $this->actingAs($owner)->withContext($acme)->put('/panel/organizasyon/kunye', ['name' => 'Acme Holding'])->assertRedirect('/panel');
        $this->assertSame('Acme Holding', $acme->fresh()->name);
        $this->assertSame('acme', $acme->fresh()->slug);
        $this->actingAs($viewer)->withContext($acme)->put('/panel/organizasyon/kunye', ['name' => 'X Y'])->assertForbidden();
    }
}
