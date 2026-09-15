<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\KycDocument;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AuthorizationService;
use App\Services\JitAccessService;
use App\Services\KycService;
use App\Services\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Matristeki üçlü ayrımın testi:
 *
 *   kyc.view          (company, JIT yok)  -> müşteri KENDİ belgesini görür
 *   kyc.view_status   (global,  JIT yok)  -> personel yalnızca DURUMU görür
 *   kyc.view_document (global,  JIT var)  -> personel İÇERİĞİ ancak JIT ile açar
 *
 * Sonuç şu olmalı: Ofisvio personeli müşterinin kimlik belgesine, müşterinin
 * kendisinden DAHA ZOR erişir. Bu kasıtlıdır (veri minimizasyonu).
 */
class KycAccessTest extends TestCase
{
    use RefreshDatabase;

    private AuthorizationService $auth;

    private JitAccessService $jit;

    private TenantContext $context;

    private Organization $acme;

    private Company $acmeLtd;

    private Company $kardesLtd;

    private KycDocument $document;

    private KycDocument $kardesBelge;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->auth = app(AuthorizationService::class);
        $this->jit = app(JitAccessService::class);
        $this->context = app(TenantContext::class);

        $this->context->runAsSystem(function () {
            $this->acme = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
            $this->acmeLtd = Company::create(['organization_id' => $this->acme->id, 'legal_name' => 'Acme Ltd']);
            // AYNI organizasyonda kardeş şirket — tenant scope bunları ayırmaz.
            $this->kardesLtd = Company::create(['organization_id' => $this->acme->id, 'legal_name' => 'Acme İkinci Ltd']);
            $this->kardesBelge = KycDocument::create([
                'company_id' => $this->kardesLtd->id,
                'type' => 'IDENTITY_DOCUMENT',
                'status' => 'PENDING',
                'original_filename' => 'kardes-kimlik.pdf',
                'storage_path' => 'kyc/2/xyz.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 2048,
            ]);
            $this->document = KycDocument::create([
                'company_id' => $this->acmeLtd->id,
                'type' => 'IDENTITY_DOCUMENT',
                'status' => 'PENDING',
                'original_filename' => 'kimlik.pdf',
                'storage_path' => 'kyc/1/abc.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 2048,
            ]);
        });

        $this->context->setActiveOrganization($this->acme->id);
        $this->ctx = ['organization_id' => $this->acme->id, 'company_id' => $this->acmeLtd->id];
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
        ]);

        return $user;
    }

    #[Test]
    public function musteri_kendi_belgesini_jitsiz_gorur(): void
    {
        $owner = $this->userWithRole('owner', ['company_id' => $this->acmeLtd->id]);

        $this->assertTrue($this->auth->can($owner, 'kyc.view', $this->ctx));
        $this->assertFalse($this->auth->requiresJit($owner, 'kyc.view', $this->ctx));
    }

    #[Test]
    public function personel_durumu_gorur_ama_belge_icerigini_goremez(): void
    {
        $admin = $this->userWithRole('system_admin');

        // Durum: evet.
        $this->assertTrue($this->auth->can($admin, 'kyc.view_status', $this->ctx));
        $this->assertFalse($this->auth->requiresJit($admin, 'kyc.view_status', $this->ctx));

        // İçerik: JIT grant olmadan HAYIR.
        $this->assertFalse(
            $this->jit->allows($admin, 'kyc.view_document', $this->ctx, 'kyc_document', $this->document->id)
        );

        // Personel kyc.view iznini HİÇ taşımaz — müşteri yolunu kullanamaz.
        $this->assertFalse($this->auth->can($admin, 'kyc.view', $this->ctx));
    }

    #[Test]
    public function jit_grant_acilinca_personel_belgeyi_acabilir(): void
    {
        $admin = $this->userWithRole('system_admin');

        $grantId = $this->jit->grant(
            $admin,
            'kyc.view_document',
            $this->ctx,
            'kyc_document',
            $this->document->id,
            'MASAK şüpheli işlem incelemesi ref#2026-114',
        );

        $this->assertNotNull($grantId);
        $this->assertTrue(
            $this->jit->allows($admin, 'kyc.view_document', $this->ctx, 'kyc_document', $this->document->id)
        );
    }

    #[Test]
    public function jit_grant_kaynak_bazlidir(): void
    {
        $admin = $this->userWithRole('system_admin');

        $other = $this->context->runAsSystem(fn () => KycDocument::create([
            'company_id' => $this->acmeLtd->id,
            'type' => 'TAX_CERTIFICATE',
            'status' => 'PENDING',
            'original_filename' => 'vergi.pdf',
            'storage_path' => 'kyc/1/def.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]));

        $this->jit->grant($admin, 'kyc.view_document', $this->ctx, 'kyc_document', $this->document->id, 'inceleme');

        // Bir belge için açılan grant, DİĞER belgeyi açmaz.
        $this->assertTrue($this->jit->allows($admin, 'kyc.view_document', $this->ctx, 'kyc_document', $this->document->id));
        $this->assertFalse($this->jit->allows($admin, 'kyc.view_document', $this->ctx, 'kyc_document', $other->id));
    }

    #[Test]
    public function suresi_dolan_grant_erisim_vermez(): void
    {
        $admin = $this->userWithRole('system_admin');
        $grantId = $this->jit->grant($admin, 'kyc.view_document', $this->ctx, 'kyc_document', $this->document->id, 'inceleme');

        DB::table('jit_access_grants')->where('id', $grantId)->update(['expires_at' => now()->subMinute()]);

        $this->assertFalse(
            $this->jit->allows($admin, 'kyc.view_document', $this->ctx, 'kyc_document', $this->document->id)
        );
    }

    #[Test]
    public function iptal_edilen_grant_erisim_vermez(): void
    {
        $admin = $this->userWithRole('system_admin');
        $grantId = $this->jit->grant($admin, 'kyc.view_document', $this->ctx, 'kyc_document', $this->document->id, 'inceleme');

        $this->jit->revoke($grantId);

        $this->assertFalse(
            $this->jit->allows($admin, 'kyc.view_document', $this->ctx, 'kyc_document', $this->document->id)
        );
    }

    #[Test]
    public function jit_sahip_olunmayan_yetkiyi_veremez(): void
    {
        // owner, kyc.view_document iznini HİÇ taşımaz. JIT bunu telafi etmemeli.
        $owner = $this->userWithRole('owner', ['company_id' => $this->acmeLtd->id]);

        $this->assertNull(
            $this->jit->grant($owner, 'kyc.view_document', $this->ctx, 'kyc_document', $this->document->id, 'denemece')
        );
    }

    #[Test]
    public function gerekcesiz_grant_acilmaz(): void
    {
        $admin = $this->userWithRole('system_admin');

        $this->assertNull(
            $this->jit->grant($admin, 'kyc.view_document', $this->ctx, 'kyc_document', $this->document->id, '   ')
        );
    }

    #[Test]
    public function grant_suresi_ust_sinirla_kisitlanir(): void
    {
        $admin = $this->userWithRole('system_admin');

        $grantId = $this->jit->grant(
            $admin, 'kyc.view_document', $this->ctx, 'kyc_document', $this->document->id,
            'uzun inceleme', null, 99999
        );

        $grant = DB::table('jit_access_grants')->find($grantId);
        $maxExpiry = now()->addMinutes(JitAccessService::MAX_TTL_MINUTES)->addMinute();

        $this->assertTrue(
            $maxExpiry->greaterThanOrEqualTo($grant->expires_at),
            'TTL üst sınırı uygulanmamış — süresiz erişime yakın bir grant açılabiliyor.'
        );
    }

    #[Test]
    public function resepsiyon_yalnizca_kendi_lokasyonunda_fiziksel_kayit_acar(): void
    {
        // user_roles.location_id gerçek bir FK'dır — sabit id ile atama yapılmaz,
        // lokasyonlar yaratılır. (İlk gerçek koşuda SQLite FK ihlaliyle yakalandı.)
        $konya = Location::create(['name' => 'Konya', 'slug' => 'konya']);
        $ankara = Location::create(['name' => 'Ankara', 'slug' => 'ankara']);

        $reception = $this->userWithRole('reception', ['location_id' => $konya->id]);

        $this->assertTrue($this->auth->can($reception, 'kyc.physical_document.log', ['location_id' => $konya->id]));
        $this->assertFalse($this->auth->can($reception, 'kyc.physical_document.log', ['location_id' => $ankara->id]));
        $this->assertFalse($this->auth->can($reception, 'kyc.approve', ['location_id' => $konya->id]));
    }

    // ---------------------------------------------------------------
    // Regresyon — ilk çalıştırma denetiminde bulunan hatalar
    // ---------------------------------------------------------------

    #[Test]
    public function kardes_sirketin_belgesi_kendi_sirket_contextiyle_acilamaz(): void
    {
        // REGRESYON: /companies/{company}/kyc/{document} route'unda belge ile
        // şirket BAĞIMSIZ parametrelerdir. TenantScope organizasyon sınırını
        // çizer, şirket sınırını değil — yani aynı holding altındaki kardeş
        // şirketin belgesi tenant scope'una TAKILMAZ. İlişki doğrulanmazsa
        // owner, kendi şirketinin id'siyle kardeş şirketin belgesini açardı.
        $owner = $this->userWithRole('owner', ['company_id' => $this->acmeLtd->id]);

        // Yetki kontrolü tek başına bunu YAKALAMAZ: owner kendi şirketi için
        // gerçekten kyc.view taşır.
        $this->assertTrue($this->auth->can($owner, 'kyc.view', $this->ctx));

        // Ama belge onun şirketine ait değil.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/bu şirkete ait değil/u');

        app(KycService::class)->openDocument($owner, $this->kardesBelge, $this->ctx);
    }

    #[Test]
    public function tenant_context_istek_boyunca_tek_ornektir(): void
    {
        // REGRESYON: TenantContext systemMode bayrağı taşır. Container'da
        // singleton olarak kayıtlı değilse app() her çağrıda yeni örnek üretir
        // ve runAsSystem() hiçbir işe yaramaz — TenantScope başka bir örneğe
        // sorduğu için bayrağı hiç görmez.
        $this->assertSame(app(TenantContext::class), app(TenantContext::class));

        $inside = null;
        app(TenantContext::class)->runAsSystem(function () use (&$inside) {
            $inside = app(TenantContext::class)->isSystemMode();
        });

        $this->assertTrue($inside, 'runAsSystem() bayrağı farklı bir örnekten görünmüyor.');
        $this->assertFalse(app(TenantContext::class)->isSystemMode());
    }

    #[Test]
    public function organizasyon_sirketten_cozulurken_tenant_scope_engel_olmaz(): void
    {
        // REGRESYON: resolveOrganizationId() companies tablosunu okur. Sorgu
        // TenantScope'tan geçerse döngüsel bağımlılık oluşur — organizasyonu
        // şirketten öğrenmeye çalışırken scope zaten organizasyonu şart koşar.
        $owner = $this->userWithRole('owner', ['organization_id' => $this->acme->id]);

        app(TenantContext::class)->clear(); // aktif context YOK

        $this->assertTrue(
            $this->auth->can($owner, 'organization.view', ['company_id' => $this->acmeLtd->id]),
            'Şirketten organizasyon çözümlemesi tenant scope tarafından engellenmiş.'
        );
    }

    // ---------------------------------------------------------------
    // Dual-control
    // ---------------------------------------------------------------

    #[Test]
    public function imha_onaysiz_yapilamaz(): void
    {
        $admin = $this->userWithRole('system_admin');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ikinci bir yetkilinin onayı/u');

        $this->jit->grant(
            $admin, 'kyc.physical_document.destroy', [], 'kyc_document', $this->document->id,
            'saklama süresi doldu'
        );
    }

    #[Test]
    public function talep_eden_kendi_talebini_onaylayamaz(): void
    {
        $admin = $this->userWithRole('system_admin');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/kendi talebini onaylayamaz/u');

        $this->jit->grant(
            $admin, 'kyc.physical_document.destroy', [], 'kyc_document', $this->document->id,
            'saklama süresi doldu', $admin
        );
    }

    #[Test]
    public function onaylayanin_da_ayni_izne_yetkisi_olmali(): void
    {
        // Bu madde olmadan dual-control anlamsızlaşır: herhangi bir kullanıcıyı
        // "onaylayan" diye yazmak imhayı mümkün kılardı.
        $admin = $this->userWithRole('system_admin');
        $owner = $this->userWithRole('owner', ['company_id' => $this->acmeLtd->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/onaylayan kullanıcının da bu izne yetkisi/u');

        $this->jit->grant(
            $admin, 'kyc.physical_document.destroy', [], 'kyc_document', $this->document->id,
            'saklama süresi doldu', $owner
        );
    }

    #[Test]
    public function gecerli_dual_control_ile_imha_grantı_acilir(): void
    {
        $admin = $this->userWithRole('system_admin');
        $approver = $this->userWithRole('super_admin');

        $grantId = $this->jit->grant(
            $admin, 'kyc.physical_document.destroy', [], 'kyc_document', $this->document->id,
            'Saklama süresi doldu — 2026 imha listesi', $approver
        );

        $this->assertNotNull($grantId);

        $grant = DB::table('jit_access_grants')->find($grantId);
        $this->assertSame($approver->id, (int) $grant->approved_by);
        $this->assertNotSame((int) $grant->user_id, (int) $grant->approved_by);
    }

    #[Test]
    public function dual_control_gerektirmeyen_izinde_onay_istenmez(): void
    {
        $admin = $this->userWithRole('system_admin');

        // kyc.view_document JIT ister ama dual-control istemez.
        $this->assertNotNull(
            $this->jit->grant($admin, 'kyc.view_document', $this->ctx, 'kyc_document', $this->document->id, 'inceleme')
        );
    }
}
