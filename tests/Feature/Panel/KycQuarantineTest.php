<?php

namespace Tests\Feature\Panel;

use App\Models\KycDocument;
use App\Providers\SecurityServiceProvider;
use App\Security\MalwareScanner;
use App\Security\ScanResult;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Faz 5 — dosya karantinası: tarama sonucu üç yola ayrılır ve karantinadaki
 * belge hiçbir yoldan açılmaz.
 */
class KycQuarantineTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Storage::fake('private');
    }

    private function scannerReturning(ScanResult $result): void
    {
        $this->app->instance(MalwareScanner::class, new class($result) implements MalwareScanner
        {
            public function __construct(private readonly ScanResult $result) {}

            public function scan(string $path): ScanResult
            {
                return $this->result;
            }
        });
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('kimlik.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    #[Test]
    public function enfekte_belge_karantinaya_alinir_ve_hic_kimse_acamaz(): void
    {
        $this->scannerReturning(ScanResult::infected('Win.Test.EICAR_HDB-1'));

        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);
        $admin = $this->staff('system_admin');

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc", ['type' => 'IDENTITY_DOCUMENT', 'file' => $this->pdf()])
            ->assertRedirect("/panel/sirketler/{$company->id}/kyc");

        $document = app(TenantContext::class)->runAsSystem(fn () => KycDocument::firstOrFail());
        $this->assertSame('QUARANTINED', $document->status->value);
        $this->assertStringStartsWith('quarantine/', $document->storage_path);
        $this->assertStringContainsString('EICAR', (string) $document->review_note);

        // Şirket KYC sürecine GİRMEZ: enfekte dosya belge sayılmaz.
        $this->assertSame('REGISTERED', $company->fresh()->status->value);

        // Müşteri gerekçeyi görür, indirme düğmesi yok.
        $this->actingAs($owner)->withContext($acme)
            ->get("/panel/sirketler/{$company->id}/kyc")
            ->assertOk()
            ->assertSee('Güvenlik taramasında reddedildi')
            ->assertDontSee('>İndir<', false);

        // URL'yi elle çağırsa bile içerik açılmaz.
        $this->actingAs($owner)->withContext($acme)
            ->get("/panel/sirketler/{$company->id}/kyc/{$document->id}/indir")
            ->assertRedirect("/panel/sirketler/{$company->id}/kyc")
            ->assertSessionHasErrors('document');

        // Personel JIT açsa bile açılmaz.
        $this->actingAs($admin)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc/{$document->id}/jit", ['reason' => 'zararlı dosya adli inceleme', 'ttl_minutes' => 30]);
        $this->assertSame(1, DB::table('jit_access_grants')->count());
        $this->actingAs($admin)->withContext($acme)
            ->get("/panel/sirketler/{$company->id}/kyc/{$document->id}/indir")
            ->assertRedirect("/panel/sirketler/{$company->id}/kyc")
            ->assertSessionHasErrors('document');

        // Kuyrukta görünmez, onaylanamaz.
        $this->actingAs($admin)->withContext($acme)->get('/panel/kyc-kuyrugu')->assertDontSee('Kimlik belgesi');
        $this->actingAs($admin)->withContext($acme)
            ->from("/panel/sirketler/{$company->id}/kyc")
            ->post("/panel/sirketler/{$company->id}/kyc/{$document->id}/onayla")
            ->assertSessionHasErrors('note');
        $this->assertSame('QUARANTINED', $document->fresh()->status->value);
    }

    #[Test]
    public function tarama_yapilamiyorsa_yukleme_reddedilir(): void
    {
        $this->scannerReturning(ScanResult::unavailable('clamd kapalı'));

        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);

        $this->actingAs($owner)->withContext($acme)
            ->from("/panel/sirketler/{$company->id}/kyc")
            ->post("/panel/sirketler/{$company->id}/kyc", ['type' => 'IDENTITY_DOCUMENT', 'file' => $this->pdf()])
            ->assertRedirect("/panel/sirketler/{$company->id}/kyc")
            ->assertSessionHasErrors('file');

        // Fail-closed: kayıt da dosya da yok.
        $this->assertSame(0, app(TenantContext::class)->runAsSystem(fn () => KycDocument::count()));
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    #[Test]
    public function temiz_belge_normal_akista_ilerler(): void
    {
        $this->scannerReturning(ScanResult::clean());

        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc", ['type' => 'IDENTITY_DOCUMENT', 'file' => $this->pdf()]);

        $document = app(TenantContext::class)->runAsSystem(fn () => KycDocument::firstOrFail());
        $this->assertSame('PENDING', $document->status->value);
        $this->assertStringStartsWith('kyc/', $document->storage_path);
        $this->assertSame('KYC_PENDING', $company->fresh()->status->value);
    }

    #[Test]
    public function tarayicisiz_yapilandirma_productionda_reddedilir(): void
    {
        config(['ofisvio.kyc.scanner' => 'none']);
        $this->app->forgetInstance(MalwareScanner::class);

        $this->app->detectEnvironment(fn () => 'production');
        (new SecurityServiceProvider($this->app))->register();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/production/');

        $this->app->make(MalwareScanner::class);
    }
}
