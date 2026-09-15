<?php

namespace Tests\Feature\Panel;

use App\Enums\KycDocumentType;
use App\Models\Company;
use App\Models\KycDocument;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F4 (müşteri: yükle, izle, indir) + F5 (personel: kuyruk, JIT, karar) —
 * uçtan uca HTTP üzerinden. Matristeki üçlü ayrım burada tarayıcı
 * davranışı olarak doğrulanır.
 */
class KycFlowTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Storage::fake('private');
    }

    private function pdf(string $name = 'vergi-levhasi.pdf'): UploadedFile
    {
        // Gerçek PDF imzası: KycService MIME'ı içerikten okur, uzantıdan değil.
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    private function upload(Company $company, KycDocumentType $type): KycDocument
    {
        return app(TenantContext::class)->runAsSystem(fn () => KycDocument::where('company_id', $company->id)
            ->where('type', $type->value)
            ->latest('id')
            ->firstOrFail());
    }

    #[Test]
    public function musteri_belge_yukler_durumu_gorur_ve_jitsiz_indirir(): void
    {
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);

        $this->actingAs($owner)->withContext($acme)
            ->get("/panel/sirketler/{$company->id}/kyc")
            ->assertOk()
            ->assertSee('Belge yükle')
            ->assertSee('Vergi levhası')
            ->assertDontSee('İnceleme bekleyen belge yok'); // inceleme bloğu müşteriye görünmez

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc", ['type' => 'TAX_CERTIFICATE', 'file' => $this->pdf()])
            ->assertRedirect("/panel/sirketler/{$company->id}/kyc")
            ->assertSessionHas('status');

        $document = $this->upload($company, KycDocumentType::TAX_CERTIFICATE);
        $this->assertSame('PENDING', $document->status->value);
        Storage::disk('private')->assertExists($document->storage_path);

        // İlk belge şirketi KYC sürecine sokar.
        $this->assertSame('KYC_PENDING', $company->fresh()->status->value);

        $this->actingAs($owner)->withContext($acme)
            ->get("/panel/sirketler/{$company->id}/kyc")
            ->assertOk()
            ->assertSee('İnceleme bekliyor')
            ->assertSee('İndir');

        // Müşteri kendi belgesini JIT'siz açar.
        $response = $this->actingAs($owner)->withContext($acme)
            ->get("/panel/sirketler/{$company->id}/kyc/{$document->id}/indir")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="vergi-levhasi.pdf"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function yanlis_dosya_tipi_reddedilir(): void
    {
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);

        $this->actingAs($owner)->withContext($acme)
            ->from("/panel/sirketler/{$company->id}/kyc")
            ->post("/panel/sirketler/{$company->id}/kyc", [
                'type' => 'TAX_CERTIFICATE',
                'file' => UploadedFile::fake()->createWithContent('zararli.exe', 'MZ...'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, app(TenantContext::class)->runAsSystem(fn () => KycDocument::count()));
    }

    #[Test]
    public function uye_olmayan_kullanici_yukleyemez(): void
    {
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $viewer = $this->member($acme);
        $this->grantRole($viewer, 'viewer', ['company_id' => $company->id]); // company.view var, kyc.upload yok

        $this->actingAs($viewer)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc", ['type' => 'TAX_CERTIFICATE', 'file' => $this->pdf()])
            ->assertForbidden();
    }

    #[Test]
    public function personel_durumu_gorur_icerigi_ancak_jit_ile_acar(): void
    {
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);
        $admin = $this->staff('system_admin');

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc", ['type' => 'IDENTITY_DOCUMENT', 'file' => $this->pdf('kimlik.pdf')]);
        $document = $this->upload($company, KycDocumentType::IDENTITY_DOCUMENT);

        // Kuyrukta görünür.
        $this->actingAs($admin)->withContext($acme)->get('/panel/kyc-kuyrugu')
            ->assertOk()
            ->assertSee('Acme Ltd')
            ->assertSee('Kimlik belgesi');

        // Durum sayfası: "İndir" yok, "JIT" var, yükleme formu yok.
        $this->actingAs($admin)->withContext($acme)
            ->get("/panel/sirketler/{$company->id}/kyc")
            ->assertOk()
            ->assertSee('JIT iste')
            ->assertSee('Belge içeriği erişimi (JIT)')
            ->assertDontSee('>İndir<', false)
            ->assertDontSee('Belge yükle');

        // Grant olmadan indirme: kapı kapalı (403 — route middleware allows() der).
        $this->actingAs($admin)->withContext($acme)
            ->get("/panel/sirketler/{$company->id}/kyc/{$document->id}/indir")
            ->assertForbidden();

        // Gerekçesiz/kısa gerekçeyle JIT açılmaz.
        $this->actingAs($admin)->withContext($acme)
            ->from("/panel/sirketler/{$company->id}/kyc")
            ->post("/panel/sirketler/{$company->id}/kyc/{$document->id}/jit", ['reason' => 'bak', 'ttl_minutes' => 30])
            ->assertSessionHasErrors('reason');

        // Gerekçeli JIT: grant açılır, süre üst sınıra kırpılır.
        $this->actingAs($admin)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc/{$document->id}/jit", [
                'reason' => 'MASAK şüpheli işlem incelemesi ref#2026-114',
                'ttl_minutes' => 60,
            ])
            ->assertRedirect("/panel/sirketler/{$company->id}/kyc")
            ->assertSessionHas('status');

        $grant = DB::table('jit_access_grants')->where('user_id', $admin->id)->first();
        $this->assertNotNull($grant);
        $this->assertSame($document->id, (int) $grant->resource_id);
        $this->assertStringContainsString('MASAK', $grant->reason);

        // Artık "İndir" görünür ve içerik açılır.
        $this->actingAs($admin)->withContext($acme)
            ->get("/panel/sirketler/{$company->id}/kyc")
            ->assertSee('>İndir<', false);

        $this->actingAs($admin)->withContext($acme)
            ->get("/panel/sirketler/{$company->id}/kyc/{$document->id}/indir")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="kimlik.pdf"');
    }

    #[Test]
    public function jit_talebi_rolde_izin_yoksa_acilmaz(): void
    {
        // finance_admin: internal, kyc.view_status YOK -> sayfaya bile giremez.
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);
        $finance = $this->staff('finance_admin');

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc", ['type' => 'IDENTITY_DOCUMENT', 'file' => $this->pdf()]);
        $document = $this->upload($company, KycDocumentType::IDENTITY_DOCUMENT);

        $this->actingAs($finance)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc/{$document->id}/jit", ['reason' => 'uzun ve geçerli bir gerekçe', 'ttl_minutes' => 30])
            ->assertForbidden();

        $this->assertSame(0, DB::table('jit_access_grants')->count());
    }

    #[Test]
    public function personel_karar_verir_ve_tum_zorunlular_onaylaninca_sirket_kyc_approved_olur(): void
    {
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);
        $admin = $this->staff('system_admin');

        foreach (KycDocumentType::required() as $type) {
            $this->actingAs($owner)->withContext($acme)
                ->post("/panel/sirketler/{$company->id}/kyc", ['type' => $type->value, 'file' => $this->pdf($type->value.'.pdf')]);
        }

        $tax = $this->upload($company, KycDocumentType::TAX_CERTIFICATE);

        // Red gerekçesiz olmaz.
        $this->actingAs($admin)->withContext($acme)
            ->from("/panel/sirketler/{$company->id}/kyc")
            ->post("/panel/sirketler/{$company->id}/kyc/{$tax->id}/reddet", ['note' => ''])
            ->assertSessionHasErrors('note');

        // Ek bilgi iste -> müşteri notu görür, şirket KYC_REVIEW'a geçer.
        $this->actingAs($admin)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc/{$tax->id}/ek-bilgi", ['note' => 'Levha okunmuyor, yeniden tarayın.'])
            ->assertRedirect("/panel/sirketler/{$company->id}/kyc");

        $this->assertSame('MORE_INFO_REQUIRED', $tax->fresh()->status->value);
        $this->assertSame('KYC_REVIEW', $company->fresh()->status->value);

        $this->actingAs($owner)->withContext($acme)
            ->get("/panel/sirketler/{$company->id}/kyc")
            ->assertSee('Levha okunmuyor, yeniden tarayın.');

        // Müşteri yeniden yükler: eski belge SUPERSEDED, silinmez.
        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc", ['type' => 'TAX_CERTIFICATE', 'file' => $this->pdf('vergi-2.pdf')]);
        $this->assertSame('SUPERSEDED', $tax->fresh()->status->value);

        // Hepsini onayla.
        $pending = app(TenantContext::class)->runAsSystem(fn () => KycDocument::where('company_id', $company->id)
            ->where('status', 'PENDING')->get());
        $this->assertCount(4, $pending);

        foreach ($pending as $doc) {
            $this->actingAs($admin)->withContext($acme)
                ->post("/panel/sirketler/{$company->id}/kyc/{$doc->id}/onayla", ['note' => null])
                ->assertRedirect("/panel/sirketler/{$company->id}/kyc");
        }

        $this->assertSame('KYC_APPROVED', $company->fresh()->status->value);

        $this->actingAs($owner)->withContext($acme)
            ->get("/panel/sirketler/{$company->id}")
            ->assertOk()
            ->assertSee('KYC onaylandı')
            ->assertSee('Zorunlu belgeler onaylı');
    }

    #[Test]
    public function musteri_karar_veremez(): void
    {
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc", ['type' => 'TAX_CERTIFICATE', 'file' => $this->pdf()]);
        $document = $this->upload($company, KycDocumentType::TAX_CERTIFICATE);

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc/{$document->id}/onayla")
            ->assertForbidden();

        $this->assertSame('PENDING', $document->fresh()->status->value);
    }

    #[Test]
    public function kardes_sirketin_belgesi_kendi_sirket_urlsiyle_acilamaz(): void
    {
        // scopeBindings: {kycDocument}, {company}->kycDocuments() üzerinden çözülür.
        $acme = $this->organization('Acme');
        $benim = $this->company($acme, 'Benim Ltd');
        $kardes = $this->company($acme, 'Kardeş Ltd');
        $owner = $this->owner($acme, $benim, $kardes);

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$kardes->id}/kyc", ['type' => 'TAX_CERTIFICATE', 'file' => $this->pdf()]);
        $kardesBelge = $this->upload($kardes, KycDocumentType::TAX_CERTIFICATE);

        $this->actingAs($owner)->withContext($acme)
            ->get("/panel/sirketler/{$benim->id}/kyc/{$kardesBelge->id}/indir")
            ->assertNotFound();
    }
}
