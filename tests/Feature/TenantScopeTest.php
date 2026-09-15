<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\KycDocument;
use App\Models\Organization;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Zincirin son halkası: bir controller YANLIŞ yazılmış olsa bile
 * başka tenant'ın kaydı dönmemeli.
 *
 * Bu testler kasıtlı olarak yetki katmanını HİÇ çağırmaz — doğrudan
 * Eloquent'e sorar. Sorulan şey şu: "savunmanın diğer katmanlarının
 * hepsi baypas edilse, bu katman tek başına tutuyor mu?"
 */
class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Organization $acme;

    private Organization $rakip;

    private Company $acmeLtd;

    private Company $acmeIkinci;

    private Company $rakipLtd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);

        // Kurulum sistem modunda: tenant scope kurulum verisini engellemesin.
        $this->context->runAsSystem(function () {
            $this->acme = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
            $this->rakip = Organization::create(['name' => 'Rakip', 'slug' => 'rakip']);

            $this->acmeLtd = Company::create(['organization_id' => $this->acme->id, 'legal_name' => 'Acme Ltd']);
            $this->acmeIkinci = Company::create(['organization_id' => $this->acme->id, 'legal_name' => 'Acme İkinci']);
            $this->rakipLtd = Company::create(['organization_id' => $this->rakip->id, 'legal_name' => 'Rakip Ltd']);

            foreach ([$this->acmeLtd, $this->acmeIkinci, $this->rakipLtd] as $company) {
                foreach (['TAX_CERTIFICATE', 'IDENTITY_DOCUMENT'] as $type) {
                    KycDocument::create([
                        'company_id' => $company->id,
                        'type' => $type,
                        'status' => 'PENDING',
                        'original_filename' => 'belge.pdf',
                        'storage_path' => 'kyc/test/'.uniqid().'.pdf',
                        'mime_type' => 'application/pdf',
                        'size_bytes' => 1024,
                    ]);
                }
            }
        });
    }

    #[Test]
    public function context_yoksa_hicbir_kayit_donmez(): void
    {
        // FAIL-CLOSED. Alternatifi "scope'u uygulama, hepsini döndür" olurdu
        // ve o davranış, context kurulmamış her yolu sessizce sızıntıya çevirirdi.
        $this->assertSame(0, KycDocument::count());
        $this->assertSame(0, Company::count());
        $this->assertNull(KycDocument::first());
    }

    #[Test]
    public function aktif_tenant_yalnizca_kendi_kayitlarini_gorur(): void
    {
        $this->context->setActiveOrganization($this->acme->id);

        $this->assertSame(4, KycDocument::count());   // 2 şirket x 2 belge
        $this->assertSame(2, Company::count());

        $this->context->setActiveOrganization($this->rakip->id);

        $this->assertSame(2, KycDocument::count());
        $this->assertSame(1, Company::count());
    }

    #[Test]
    public function baska_tenantin_kaydi_id_ile_bile_cekilemez(): void
    {
        // Sızıntının en sinsi hali: saldırgan ID'yi biliyor ve doğrudan
        // istiyor. find() null dönmeli, "bulundu ama gösterme" değil.
        $rakipDocument = $this->context->runAsSystem(
            fn () => KycDocument::where('company_id', $this->rakipLtd->id)->firstOrFail()
        );

        $this->context->setActiveOrganization($this->acme->id);

        $this->assertNull(KycDocument::find($rakipDocument->id));
        $this->assertNull(Company::find($this->rakipLtd->id));
        $this->assertSame(0, KycDocument::where('id', $rakipDocument->id)->count());
    }

    #[Test]
    public function sistem_modu_scopeu_kapatir_ve_geri_acar(): void
    {
        $this->context->setActiveOrganization($this->acme->id);
        $this->assertSame(4, KycDocument::count());

        $all = $this->context->runAsSystem(fn () => KycDocument::count());
        $this->assertSame(6, $all);

        // Blok bitince scope geri gelmeli.
        $this->assertSame(4, KycDocument::count());
    }

    #[Test]
    public function sistem_modu_istisna_atilsa_bile_geri_kapanir(): void
    {
        $this->context->setActiveOrganization($this->acme->id);

        try {
            $this->context->runAsSystem(function () {
                throw new \RuntimeException('patladı');
            });
        } catch (\RuntimeException) {
            // beklenen
        }

        $this->assertFalse($this->context->isSystemMode());
        $this->assertSame(4, KycDocument::count(), 'İstisna sonrası scope açık kalmış — sızıntı riski.');
    }

    #[Test]
    public function without_tenant_scope_acikca_cagrildiginda_calisir(): void
    {
        $this->context->setActiveOrganization($this->acme->id);

        $this->assertSame(4, KycDocument::count());
        $this->assertSame(6, KycDocument::withoutTenantScope()->count());
    }

    #[Test]
    public function yeni_sirket_aktif_organizasyona_baglanir(): void
    {
        $this->context->setActiveOrganization($this->rakip->id);

        $company = Company::create(['legal_name' => 'Yeni Şirket']);

        $this->assertSame($this->rakip->id, (int) $company->organization_id);
    }

    #[Test]
    public function tenant_scope_ayni_organizasyondaki_kardes_sirketi_ayirmaz(): void
    {
        // Bilinçli sınır: TenantScope ORGANIZASYON sınırını çizer.
        // Aynı org içindeki kardeş şirketleri ayırmak AuthorizationService'in
        // işidir. Bu test o iş bölümünü belgeliyor — değişirse bilinçli olsun.
        $this->context->setActiveOrganization($this->acme->id);

        $this->assertSame(4, KycDocument::count());
        $this->assertSame(
            2,
            KycDocument::where('company_id', $this->acmeIkinci->id)->count(),
            'Kardeş şirketin kaydı model katmanında görünür; ayrımı RBAC yapar.'
        );
    }
}
