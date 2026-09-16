<?php

namespace Tests\Feature\Panel;

use App\Models\Website;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sistem ekranları: denetim kaydı (audit.view — JIT, organizasyon girişi, şirket
 * durum geçişi gerçek kayıtlardan) ve performans paneli (performance.view /
 * performance.audit — baseline, önbellek, doctor). İzinler matristen.
 */
class SystemPanelsTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(LocationSeeder::class);
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
    }

    #[Test]
    public function denetim_kaydi_gercek_olaylari_listeler_ve_suzer(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // audit.view yok
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $company);
        $site = Website::query()->default()->firstOrFail();

        // Olaylar: JIT (önbellek ayarı), personel organizasyon girişi, şirket açılışı (durum geçişi).
        $this->actingAs($admin)->post("/panel/onbellek/{$site->id}/jit", ['reason' => 'denetim testi için erişim', 'ttl_minutes' => 15])->assertRedirect();
        $this->actingAs($admin)->post('/panel/organizasyon', ['organization_id' => $acme->id])->assertRedirect();
        // İlk KYC yüklemesi REGISTERED -> KYC_PENDING geçişini kaydeder (şirket açılışı geçiş değildir).
        Storage::fake('private');
        $this->actingAs($owner)->withContext($acme)->post("/panel/sirketler/{$company->id}/kyc", ['type' => 'TAX_CERTIFICATE', 'file' => UploadedFile::fake()->create('vergi.pdf', 10, 'application/pdf')])->assertRedirect();

        $this->actingAs($admin)->get('/panel/denetim')->assertOk()->assertSee('denetim testi için erişim')->assertSee('cache.invalidate')->assertSee('Etkin');
        $this->actingAs($admin)->get('/panel/denetim?tur=context')->assertOk()->assertSee($admin->name)->assertSee('Acme')->assertSee('Personel');
        $this->actingAs($admin)->get('/panel/denetim?tur=company')->assertOk()->assertSee('Acme A.Ş.')->assertSee('KYC_PENDING')->assertSee($owner->name);

        // Webhook ve entegrasyon sekmeleri (boş ama erişilebilir).
        $this->actingAs($admin)->get('/panel/denetim?tur=webhook')->assertOk()->assertSee('eşleşen kayıt yok');
        $this->actingAs($admin)->get('/panel/denetim?tur=integration')->assertOk()->assertSee('eşleşen kayıt yok');

        // Süzgeç: arama ve tarih; geçersiz tür reddedilir.
        $this->actingAs($admin)->get('/panel/denetim?q=olmayan-metin')->assertOk()->assertSee('eşleşen kayıt yok');
        $this->actingAs($admin)->get('/panel/denetim?from=2099-01-01')->assertOk()->assertSee('eşleşen kayıt yok');
        $this->actingAs($admin)->get('/panel/denetim?tur=bozuk')->assertSessionHasErrors('tur');

        // Yetki: operations_admin ve müşteri sahibi göremez.
        $this->actingAs($ops)->get('/panel/denetim')->assertForbidden();
        $this->actingAs($owner)->withContext($acme)->get('/panel/denetim')->assertForbidden();
    }

    #[Test]
    public function performans_paneli_baseline_onbellek_ve_doctor_gosterir(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // performance.view var, audit yok
        $path = storage_path('app/perf/baseline.json');
        File::delete($path);

        $this->actingAs($ops)->get('/panel/performans')->assertOk()->assertSee('Henüz baseline yok')->assertDontSee('/panel/performans/olc', false)->assertDontSee('/panel/performans/doctor', false);
        $this->actingAs($ops)->post('/panel/performans/olc')->assertForbidden();
        $this->actingAs($ops)->get('/panel/performans/doctor')->assertForbidden();

        // Ölçüm (üretim dışı): JSON yazılır, ekranda sayfa satırları görünür.
        $this->actingAs($admin)->post('/panel/performans/olc')->assertRedirect('/panel/performans');
        $this->assertFileExists($path);
        $this->actingAs($admin)->get('/panel/performans')->assertOk()->assertSee('Vitrin ana sayfa')->assertSee('İçerik takvimi')->assertSee('Ofisvio');
        $this->actingAs($admin)->get('/panel/performans')->assertOk()->assertSee('Entegrasyon geçidi')->assertSee('iyzico')->assertSee('Kapalı')->assertDontSee('gizli');

        // Doctor ekranı: kontrol satırları.
        $this->actingAs($admin)->get('/panel/performans/doctor')->assertOk()->assertSee('APP_KEY')->assertSee('RBAC matrisi');

        // Üretimde ölçüm reddedilir.
        config(['app.env' => 'production']);
        $this->actingAs($admin)->post('/panel/performans/olc')->assertRedirect('/panel/performans')->assertSessionHasErrors('baseline');

        File::delete($path);
        $this->actingAs($this->owner($this->organization('Beta')))->get('/panel/performans')->assertForbidden();
    }
}
