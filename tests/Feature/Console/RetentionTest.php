<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ConsentRecord;
use App\Models\KycDocument;
use App\Models\Lead;
use App\Models\Organization;
use App\Services\LeadService;
use App\Services\RetentionService;
use App\Services\SettingsService;
use App\Services\TenantContext;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Panel\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Audit F-09 (KVKK saklama): süresi dolan vitrin kayıtları anonimleşir (satır + rıza izi kalır), yeni kayıt dokunulmaz,
 * dry-run değiştirmez, eski KYC dosyası ayar açıkken silinir (kayıt kalır), 0 = kapalı; koşu audit'e adet yazar.
 */
class RetentionTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
    }

    #[Test]
    public function suresi_dolan_kayitlar_anonimlesir_yeniler_kalir_kyc_dosyasi_ayarla_silinir(): void
    {
        $settings = app(SettingsService::class);
        $leads = app(LeadService::class);

        $old = $leads->capture(['kind' => 'quote', 'name' => 'Eski Kişi', 'email' => 'eski@example.com', 'phone' => '+905551112233', 'note' => 'gizli not'], ['ip' => '203.0.113.1', 'user_agent' => 'ua']);
        $old->forceFill(['created_at' => Carbon::now()->subMonths(30)])->save();
        $fresh = $leads->capture(['kind' => 'quote', 'name' => 'Yeni Kişi', 'email' => 'yeni@example.com'], ['ip' => '203.0.113.2']);

        // Varsayılan 24 ay: dry-run yalnız sayar.
        $this->artisan('ofisvio:retention', ['--dry-run' => true])->expectsOutputToContain('leads                    1 (dry-run)')->assertSuccessful();
        $this->assertSame('eski@example.com', $old->fresh()->email);
        $this->assertFalse(AuditLog::query()->where('action', 'privacy.retention_run')->exists());

        // 0 = kapalı.
        $settings->set(null, 'privacy.lead_retention_months', 0);
        $this->assertSame(0, app(RetentionService::class)->run()['leads']);

        $settings->set(null, 'privacy.lead_retention_months', 24);
        $counts = app(RetentionService::class)->run();
        $this->assertSame(1, $counts['leads']);
        $old->refresh();
        $this->assertSame(['Anonim', RetentionService::ANON_EMAIL, null, null, null], [$old->name, $old->email, $old->phone, $old->note, $old->consent_ip]);
        $this->assertNotNull($old->anonymized_at);
        $this->assertSame('yeni@example.com', $fresh->fresh()->email);
        $this->assertSame(2, ConsentRecord::query()->where('subject_type', 'lead')->count(), 'rıza izi silinmez');
        $this->assertSame(1, Lead::query()->whereNull('anonymized_at')->count());
        $this->assertSame(0, app(RetentionService::class)->run()['leads'], 'ikinci koşu idempotent');
        $this->assertTrue(AuditLog::query()->where('action', 'privacy.retention_run')->exists());
        $this->assertStringNotContainsString('eski@example.com', json_encode(AuditLog::query()->get()->toArray()));

        // KYC: varsayılan 0 → dokunulmaz; 6 ay açılınca yalnız SUPERSEDED/REJECTED/QUARANTINED ve eski dosya silinir, PENDING kalır.
        Storage::fake('private');
        Storage::disk('private')->put('kyc/1/eski.pdf', 'x');
        Storage::disk('private')->put('kyc/1/bekleyen.pdf', 'x');
        [$superseded, $pending] = app(TenantContext::class)->runAsSystem(function () {
            $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
            $co = Company::create(['organization_id' => $org->id, 'legal_name' => 'Acme Ltd']);
            $s = KycDocument::create(['company_id' => $co->id, 'type' => 'IDENTITY_DOCUMENT', 'status' => 'SUPERSEDED', 'original_filename' => 'eski.pdf', 'storage_path' => 'kyc/1/eski.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1]);
            $p = KycDocument::create(['company_id' => $co->id, 'type' => 'IDENTITY_DOCUMENT', 'status' => 'PENDING', 'original_filename' => 'bekleyen.pdf', 'storage_path' => 'kyc/1/bekleyen.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1]);
            KycDocument::withoutTenantScope()->whereKey([$s->id, $p->id])->update(['updated_at' => Carbon::now()->subMonths(12)]);

            return [$s, $p];
        });
        $this->assertSame(0, app(RetentionService::class)->run()['kyc_files']);
        Storage::disk('private')->assertExists('kyc/1/eski.pdf');

        $settings->set(null, 'privacy.kyc_superseded_retention_months', 6);
        $this->assertSame(1, app(RetentionService::class)->run()['kyc_files']);
        Storage::disk('private')->assertMissing('kyc/1/eski.pdf');
        Storage::disk('private')->assertExists('kyc/1/bekleyen.pdf');
        $this->assertNotNull(KycDocument::withoutTenantScope()->find($superseded->id)?->purged_at);
        $this->assertNull(KycDocument::withoutTenantScope()->find($pending->id)?->purged_at);

        // Ayar ekranı: Gizlilik & saklama grubu görünür ve yazılabilir.
        $admin = $this->staff('system_admin');
        $this->actingAs($admin)->get('/panel/ayarlar?grup=privacy')->assertOk()->assertSee('Gizlilik')->assertSee('Talep/form kayıtları (ay)');
    }
}
