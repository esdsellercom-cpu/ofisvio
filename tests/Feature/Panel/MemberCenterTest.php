<?php

namespace Tests\Feature\Panel;

use App\Models\Contract;
use App\Models\Document;
use App\Models\ExtraCharge;
use App\Models\Invoice;
use App\Models\MemberProfile;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserRole;
use App\Security\MalwareScanner;
use App\Security\ScanResult;
use App\Services\InvoiceService;
use App\Services\MemberCenterService;
use Database\Seeders\NotificationRuleSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 51 — 360° üye merkezi: + Yeni üye (profil, üye no, ilk sözleşme, avatar), genişletilmiş dizin (finans/sözleşme
 * sütunları, süzgeçler, arama), profil sekmeleri (finans/sözleşme/tahsis/hizmet/belge/aktivite), ek harcama (faturalı /
 * faturasız), mevcut tahsilat rotasına `return` ile dönüş, sözleşme dosya/PDF/sonlandırma, uyarılar, yetki sınırları.
 */
class MemberCenterTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->seed(NotificationRuleSeeder::class);
        Carbon::setTestNow('2026-09-18 10:00:00');
        Storage::fake('local');
        Storage::fake('public');
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(string $path): ScanResult
            {
                return ScanResult::clean();
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private string $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    #[Test]
    public function yeni_uye_profil_uye_no_ilk_sozlesme_ve_avatar_ile_olusur_dizin_arar_ve_suzer(): void
    {
        $org = $this->organization('Acme');
        $co = $this->company($org, 'Acme A.Ş.');
        $this->owner($org, $co);
        $admin = $this->staff('system_admin');

        $this->actingAs($admin)->withContext($org)->get('/panel/uyeler')->assertOk()->assertSee('+ Yeni üye')->assertSee('Sözleşme bitişi')->assertSee('Bakiye');
        $this->actingAs($admin)->withContext($org)->get('/panel/uyeler/yeni')->assertOk()->assertSee('Üyeyi oluştur')->assertSee('İlk sözleşme');

        $this->actingAs($admin)->withContext($org)->post("/panel/uyeler/{$co->id}", [
            'first_name' => 'Ayşe', 'last_name' => 'Demir', 'email' => 'ayse@acme.test', 'phone' => '0532 000 11 22', 'title' => 'Genel Müdür', 'identity_number' => '12345678901',
            'address' => 'Levent', 'city' => 'İstanbul', 'country' => 'Türkiye', 'membership_type' => 'sanal_ofis', 'member_since' => '2026-09-01', 'status' => 'active', 'note' => 'VIP',
            'contract_type' => 'sanal_ofis', 'contract_starts_on' => '2026-09-01', 'contract_ends_on' => '2026-10-01', 'role' => 'employee',
            'avatar' => UploadedFile::fake()->createWithContent('ayse.png', (string) base64_decode($this->png, true)),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'ayse@acme.test')->firstOrFail();
        $this->assertSame('Ayşe Demir', $user->name);
        $member = UserRole::query()->where('user_id', $user->id)->where('company_id', $co->id)->firstOrFail();
        $profile = $member->profile;
        $this->assertSame('UYE-000001', $profile->member_no);
        $this->assertSame('sanal_ofis', $profile->membership_type);
        $this->assertNotNull($profile->avatar_media_id);
        $this->assertSame('********901', $profile->maskedIdentity());
        $contract = Contract::withoutTenantScope()->where('company_id', $co->id)->firstOrFail();
        $this->assertSame('SOZ-2026-000001', $contract->number);
        $this->assertSame(13, $contract->daysLeft());

        // Dizin: sütunlar, arama (üye no / telefon / firma), süzgeçler.
        $dir = $this->actingAs($admin)->withContext($org)->get('/panel/uyeler')->assertOk();
        $dir->assertSee('Ayşe Demir')->assertSee('UYE-000001')->assertSee('01.10.2026')->assertSee('13 gün');
        $this->actingAs($admin)->withContext($org)->get('/panel/uyeler?q=UYE-000001')->assertOk()->assertSee('Ayşe Demir');
        $this->actingAs($admin)->withContext($org)->get('/panel/uyeler?q=05320001122')->assertOk()->assertSee('Ayşe Demir');
        $this->actingAs($admin)->withContext($org)->get('/panel/uyeler?q=yok-boyle')->assertOk()->assertDontSee('Ayşe Demir');
        $this->actingAs($admin)->withContext($org)->get('/panel/uyeler?f=contract_soon')->assertOk()->assertSee('Ayşe Demir');
        $this->actingAs($admin)->withContext($org)->get('/panel/uyeler?f=debtor')->assertOk()->assertDontSee('Ayşe Demir');
        $this->actingAs($admin)->withContext($org)->get('/panel/uyeler?f=new')->assertOk()->assertSee('Ayşe Demir');

        // Profil: özet kartları, uyarı (sözleşme 13 gün), sekmeler; düzenleme.
        $show = $this->actingAs($admin)->withContext($org)->get("/panel/uyeler/{$member->id}")->assertOk();
        $show->assertSee('Ayşe Demir')->assertSee('UYE-000001')->assertSee('13 gün kaldı')->assertSee('13 gün içinde sona eriyor')->assertSee('Tahsilat')->assertSee('Ek harcama')->assertSee('Sözleşme ekle')->assertSee('Hizmet ekle')->assertSee('Tahsis et')->assertSee('Belge oluştur')->assertSee('Düzenle');
        $this->actingAs($admin)->withContext($org)->put("/panel/uyeler/{$co->id}/{$member->id}", ['first_name' => 'Ayşe', 'last_name' => 'Demir Kaya', 'phone' => '0532 000 11 22', 'status' => 'suspended', 'membership_type' => 'coworking'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Ayşe Demir Kaya', $user->fresh()->name);
        $this->assertFalse($member->fresh()->isActive());
        $this->assertSame('coworking', $profile->fresh()->membership_type);
        $this->actingAs($admin)->withContext($org)->get('/panel/uyeler?f=passive')->assertOk()->assertSee('Ayşe Demir Kaya');
        $this->actingAs($admin)->withContext($org)->get("/panel/uyeler/{$member->id}?sekme=aktivite")->assertOk()->assertSee('Üye oluşturuldu')->assertSee('Sözleşme oluşturuldu')->assertSee('Üye bilgileri güncellendi')->assertSee($admin->name);
    }

    #[Test]
    public function finans_ek_harcama_tahsilat_donusu_hareketler_ve_uyarilar_sirket_verisinden_gelir(): void
    {
        $org = $this->organization('Acme');
        $co = $this->company($org, 'Acme A.Ş.');
        $owner = $this->owner($org, $co);
        $admin = $this->staff('system_admin');
        $member = UserRole::query()->where('user_id', $owner->id)->where('company_id', $co->id)->firstOrFail();
        $show = "/panel/uyeler/{$member->id}";

        // Fatura (mevcut servis) → borç; vadesi geçmiş → gecikme uyarısı.
        $invoice = app(InvoiceService::class)->create($admin, $co, ['description' => 'Sanal ofis Eylül', 'subtotal' => '1000', 'tax_rate' => 20, 'due_on' => '2026-09-10', 'issue' => true]);
        $page = $this->actingAs($admin)->withContext($org)->get($show)->assertOk();
        $page->assertSee('Ödeme gecikmiş')->assertSee('Ödenmemiş borç bulunuyor')->assertSee('1.200,00');

        // Ek harcama: faturalı → yeni fatura kesilir (invoice.issue), faturasız → bakiyeye doğrudan; biçim hatası.
        $this->actingAs($admin)->withContext($org)->post("/panel/uyeler/{$co->id}/{$member->id}/harcama", ['kind' => 'print', 'description' => '200 sayfa baskı', 'amount' => '150', 'charged_on' => '2026-09-18', 'billing' => 'invoice'])->assertRedirect($show.'?sekme=finans')->assertSessionHasNoErrors();
        $charge = ExtraCharge::withoutTenantScope()->firstOrFail();
        $this->assertNotNull($charge->invoice_id);
        $this->assertSame('issued', $charge->invoice->status);
        $this->assertStringContainsString('Ek harcama: Baskı', $charge->invoice->description);
        $this->actingAs($admin)->withContext($org)->post("/panel/uyeler/{$co->id}/{$member->id}/harcama", ['kind' => 'damage', 'description' => 'Kırık cam', 'amount' => '250,50', 'charged_on' => '2026-09-18', 'billing' => 'none'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->withContext($org)->from($show)->post("/panel/uyeler/{$co->id}/{$member->id}/harcama", ['kind' => 'yok', 'description' => 'x', 'amount' => '1', 'charged_on' => '2026-09-18', 'billing' => 'none'])->assertSessionHasErrors('kind');

        $finance = app(MemberCenterService::class)->finance($co);
        $this->assertSame(120000 + 18000 + 25050, $finance['invoiced'], 'fatura 1.200 + baskı faturası 180 (KDV dahil) + faturasız 250,50');
        $this->assertSame(25050, $finance['uninvoiced']);
        $this->assertSame(120000 + 18000 + 25050, $finance['remaining']);
        $this->assertSame(120000, $finance['overdue']);

        // Tahsilat mevcut rota üzerinden, `return` ile profile döner; finans ve hareketler güncellenir.
        $this->actingAs($admin)->withContext($org)->post('/panel/tahsilat/tahsilat', ['company_id' => $co->id, 'invoice_id' => $invoice->id, 'amount' => '700', 'method' => 'transfer', 'paid_on' => '2026-09-18', 'return' => $show.'?sekme=finans'])
            ->assertRedirect($show.'?sekme=finans')->assertSessionHasNoErrors();
        $this->actingAs($admin)->withContext($org)->post('/panel/tahsilat/tahsilat', ['company_id' => $co->id, 'invoice_id' => $invoice->id, 'amount' => '1', 'method' => 'cash', 'paid_on' => '2026-09-18', 'return' => 'https://kotu.example/'])
            ->assertRedirect('/panel/tahsilat#tahsilatlar');
        $finance = app(MemberCenterService::class)->finance($co);
        $this->assertSame(70100, $finance['paid']);
        $this->assertSame('2026-09-18', $finance['last_payment']->toDateString());
        $fin = $this->actingAs($admin)->withContext($org)->get($show.'?sekme=finans')->assertOk();
        $fin->assertSee('Hareket geçmişi')->assertSee('Tahsilat (Havale / EFT)')->assertSee('Ek harcama · Hasar')->assertSee('Ek harcama · Baskı (faturalandı)')->assertSee('Fatura '.$invoice->number)->assertSee('+700,00')->assertSee('−250,50')->assertSee($admin->name);
        $ledger = app(MemberCenterService::class)->ledger($co);
        $this->assertSame(0, collect($ledger)->where('type', 'charge')->where('label', 'Ek harcama · Baskı (faturalandı)')->first()['amount'], 'Faturalanan harcama tutarı faturada sayılır, çift sayılmaz.');

        // Belgeler sekmesi fatura + makbuz + gecikme belgesi; aktivite ödeme alındı.
        $payment = Payment::withoutTenantScope()->where('amount', 70000)->firstOrFail();
        $this->actingAs($admin)->withContext($org)->post("/panel/tahsilat/tahsilat/{$payment->id}/makbuz", ['return' => $show.'?sekme=belge'])->assertRedirect($show.'?sekme=belge');
        $this->actingAs($admin)->withContext($org)->post("/panel/tahsilat/fatura/{$invoice->id}/gecikme-belgesi", ['return' => $show.'?sekme=belge'])->assertRedirect($show.'?sekme=belge');
        $this->actingAs($admin)->withContext($org)->get($show.'?sekme=belge')->assertOk()->assertSee('MKB-2026-000001')->assertSee('GOB-2026-000001')->assertSee($invoice->number);
        $this->actingAs($admin)->withContext($org)->get($show.'?sekme=aktivite')->assertOk()->assertSee('Ödeme alındı')->assertSee('Ek harcama eklendi')->assertSee('Belge oluşturuldu');
        $this->actingAs($admin)->withContext($org)->get('/panel/uyeler?f=debtor')->assertOk()->assertSee($owner->name);
    }

    #[Test]
    public function sozlesme_dosya_pdf_belge_sonlandirma_ve_yetki_sinirlari(): void
    {
        $org = $this->organization('Acme');
        $co = $this->company($org, 'Acme A.Ş.');
        $owner = $this->owner($org, $co);
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // membership.manage (global) var; subscription.manage/invoice.issue yok
        $member = UserRole::query()->where('user_id', $owner->id)->where('company_id', $co->id)->firstOrFail();
        $base = "/panel/uyeler/{$co->id}/{$member->id}";

        $this->actingAs($admin)->withContext($org)->post("$base/sozlesme", ['type' => 'hazir_ofis', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'status' => 'active', 'note' => 'Oda 204', 'file' => UploadedFile::fake()->create('sozlesme.pdf', 40, 'application/pdf')])
            ->assertRedirect("/panel/uyeler/{$member->id}?sekme=sozlesme")->assertSessionHasNoErrors();
        $contract = Contract::withoutTenantScope()->firstOrFail();
        $this->assertSame('SOZ-2026-000001', $contract->number);
        $this->assertNotNull($contract->file_path);
        Storage::disk('local')->assertExists($contract->file_path);
        $this->actingAs($admin)->withContext($org)->from("/panel/uyeler/{$member->id}")->post("$base/sozlesme", ['type' => 'uyelik', 'starts_on' => '2026-09-01', 'ends_on' => '2026-08-01'])->assertSessionHasErrors('starts_on');
        $this->actingAs($admin)->withContext($org)->from("/panel/uyeler/{$member->id}")->post("$base/sozlesme", ['type' => 'uyelik', 'starts_on' => '2026-09-01', 'file' => UploadedFile::fake()->create('zararli.exe', 10, 'application/octet-stream')])->assertSessionHasErrors('file');

        // Sekme: satır, indir, PDF; dosya indirme görünürlükle.
        $this->actingAs($admin)->withContext($org)->get("/panel/uyeler/{$member->id}?sekme=sozlesme")->assertOk()->assertSee('SOZ-2026-000001')->assertSee('Hazır ofis')->assertSee('31.08.2027')->assertSee('İndir')->assertSee('Sonlandır');
        $this->actingAs($admin)->withContext($org)->get("/panel/uyeler/{$member->id}/sozlesme/{$contract->id}/dosya")->assertOk()->assertHeader('content-disposition');
        $this->actingAs($admin)->withContext($org)->post("$base/sozlesme/{$contract->id}/belge")->assertRedirect();
        $doc = Document::query()->where('kind', 'contract')->firstOrFail();
        $this->assertSame('SZB-2026-000001', $doc->number);
        $this->assertSame($contract->id, $doc->contract_id);
        $this->assertSame('SOZ-2026-000001', $doc->data['contract_number']);
        $this->actingAs($admin)->withContext($org)->get("/panel/tahsilat/belge/{$doc->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

        // Güncelle → bitiş 10 gün sonra → uyarı; sonlandır → gerekçe zorunlu, artık düzenlenemez.
        $this->actingAs($admin)->withContext($org)->put("$base/sozlesme/{$contract->id}", ['type' => 'hazir_ofis', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-28', 'status' => 'active'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->withContext($org)->get("/panel/uyeler/{$member->id}")->assertOk()->assertSee('10 gün içinde sona eriyor')->assertSee('10 gün kaldı');
        $this->actingAs($admin)->withContext($org)->from("/panel/uyeler/{$member->id}")->post("$base/sozlesme/{$contract->id}/sonlandir", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->withContext($org)->post("$base/sozlesme/{$contract->id}/sonlandir", ['reason' => 'Müşteri taşındı'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ended', $contract->fresh()->status);
        $this->actingAs($admin)->withContext($org)->from("/panel/uyeler/{$member->id}")->put("$base/sozlesme/{$contract->id}", ['type' => 'hazir_ofis', 'starts_on' => '2026-09-01'])->assertSessionHasErrors('starts_on');
        $this->actingAs($admin)->withContext($org)->get('/panel/uyeler?f=contract_over')->assertOk()->assertSee($owner->name);

        // Yetki: operasyon sözleşme/ek harcama yazamaz (403) ama profili görür; sahip kendi profilini görür; başka org 404.
        $this->actingAs($ops)->withContext($org)->post("$base/sozlesme", ['type' => 'uyelik', 'starts_on' => '2026-09-01'])->assertForbidden();
        $this->actingAs($ops)->withContext($org)->post("$base/harcama", ['kind' => 'other', 'description' => 'x', 'amount' => '1', 'charged_on' => '2026-09-18', 'billing' => 'none'])->assertForbidden();
        // Operasyon şirket görünürlüğü taşımaz (dizinle aynı kural) → 404; sahip kendi şirketini görür ama finans/sözleşme yazamaz.
        $this->actingAs($ops)->withContext($org)->get("/panel/uyeler/{$member->id}")->assertNotFound();
        $this->actingAs($owner)->withContext($org)->get("/panel/uyeler/{$member->id}")->assertOk()->assertSee($owner->name)->assertDontSee('id="modal-charge"', false)->assertDontSee('id="modal-contract"', false)->assertSee('Düzenle');
        $this->actingAs($owner)->withContext($org)->post("$base/sozlesme", ['type' => 'uyelik', 'starts_on' => '2026-09-01'])->assertForbidden();
        $this->actingAs($owner)->withContext($org)->post("$base/harcama", ['kind' => 'other', 'description' => 'x', 'amount' => '1', 'charged_on' => '2026-09-18', 'billing' => 'none'])->assertForbidden();
        $other = $this->organization('Globex');
        $otherCo = $this->company($other, 'Globex Ltd.');
        $stranger = $this->owner($other, $otherCo);
        $this->actingAs($stranger)->withContext($other)->get("/panel/uyeler/{$member->id}")->assertNotFound();
        $this->actingAs($stranger)->withContext($other)->get("/panel/uyeler/{$member->id}/sozlesme/{$contract->id}/dosya")->assertNotFound();
        $this->assertSame(0, MemberProfile::count(), 'Sahip fixture ile eklendi; profil formdan oluşur.');
        $this->assertSame(1, Contract::withoutTenantScope()->count());
        $this->assertSame(0, Invoice::withoutTenantScope()->count());
    }
}
