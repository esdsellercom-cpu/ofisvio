<?php

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\InvoiceService;
use App\Support\NumberWords;
use Database\Seeders\NotificationRuleSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 47 — Tahsilat & belge merkezi: manuel tahsilat (kaydet / kaydet ve makbuz), fatura ve müşteri hesabına yansıma,
 * nakit işareti, tahsilat iptali (silme yok, bakiye geri alınır, makbuz iptal), makbuz numarası/önizleme/PDF/yazdır/
 * düzenle, geciken ödemeler sekmesi ve belgesi, şablon düzenleme (yer tutucular, canlı önizleme verisi), yetki.
 */
class CollectionCenterTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Company, 1: User, 2: Invoice} */
    private function invoice(int $totalMinor = 120000, ?string $dueOn = '2026-09-25'): array
    {
        $org = $this->organization('Acme');
        $co = $this->company($org, 'Acme A.Ş.');
        $owner = $this->owner($org, $co);
        $finance = $this->staff('finance_admin');
        $invoice = app(InvoiceService::class)->create($finance, $co, ['description' => 'Sanal ofis Ekim', 'subtotal' => (string) ($totalMinor / 100 / 1.2), 'tax_rate' => 20, 'due_on' => $dueOn, 'issue' => true]);

        return [$co, $owner, $invoice->fresh()];
    }

    #[Test]
    public function manuel_tahsilat_faturaya_yansir_makbuz_olusur_iptal_bakiyeyi_geri_alir(): void
    {
        [$co, $owner, $invoice] = $this->invoice();
        $finance = $this->staff('finance_admin');

        // Ekran: sekmeler, + Manuel tahsilat, açık fatura satırında Tahsilat düğmesi.
        $this->actingAs($finance)->get('/panel/tahsilat')->assertOk()->assertSee('+ Manuel tahsilat')->assertSee('Geciken ödeme belgesi')->assertSee('Belge şablonları')->assertSee('modal-payment')->assertSee('Kaydet ve makbuz oluştur')
            ->assertSee('Açık faturalar')->assertSee('Aylık tahsilat')->assertSee('Yaklaşan üyelik bitişleri')->assertSee('Tahsilat ekle')->assertSee('gün kaldı'); // mevcut takip ekranı aynen (faz 39c)

        // Para birimi uyuşmazlığı ve ileri tarih reddedilir; kalan bakiyeyi aşan tutar reddedilir.
        $this->actingAs($finance)->from('/panel/tahsilat')->post('/panel/tahsilat/tahsilat', ['invoice_id' => $invoice->id, 'amount' => '100', 'currency' => 'USD', 'method' => 'cash', 'paid_on' => '2026-09-18'])->assertSessionHasErrors('amount');
        $this->actingAs($finance)->from('/panel/tahsilat')->post('/panel/tahsilat/tahsilat', ['invoice_id' => $invoice->id, 'amount' => '100', 'method' => 'cash', 'paid_on' => '2026-09-19'])->assertSessionHasErrors('amount');
        $this->actingAs($finance)->from('/panel/tahsilat')->post('/panel/tahsilat/tahsilat', ['invoice_id' => $invoice->id, 'amount' => '5000', 'method' => 'cash', 'paid_on' => '2026-09-18'])->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::withoutTenantScope()->count());

        // Kaydet: nakit kısmi tahsilat → fatura kısmi, müşteri hesabında görünür, nakit işaretli.
        $this->actingAs($finance)->post('/panel/tahsilat/tahsilat', ['company_id' => $co->id, 'invoice_id' => $invoice->id, 'amount' => '500,00', 'currency' => 'TRY', 'method' => 'cash', 'paid_on' => '2026-09-18', 'description' => 'Sanal ofis Ekim', 'reference' => 'Kasa fişi 12', 'note' => 'Elden alındı'])
            ->assertRedirect('/panel/tahsilat#tahsilatlar')->assertSessionHasNoErrors();
        $payment = Payment::withoutTenantScope()->firstOrFail();
        $this->assertSame([50000, 'TRY', 'cash', 'recorded', 'Sanal ofis Ekim'], [$payment->amount, $payment->currency, $payment->method, $payment->status, $payment->description]);
        $this->assertSame([50000, 'issued'], [$invoice->fresh()->paid_amount, $invoice->fresh()->status]);
        $this->actingAs($finance)->get('/panel/tahsilat')->assertOk()->assertSee('Kasa fişi 12')->assertSee('nakit')->assertSee('Makbuz oluştur');
        $this->actingAs($owner)->withContext($co->organization)->get("/panel/sirketler/{$co->id}/faturalar/{$invoice->id}")->assertOk()->assertSee('Nakit')->assertSee('500,00');

        // Kaydet ve makbuz oluştur: kalan tutar, POS; belge numarası MKB-2026-000001, önizleme/PDF/yazdır.
        $this->actingAs($finance)->post('/panel/tahsilat/tahsilat', ['invoice_id' => $invoice->id, 'amount' => '700', 'method' => 'card', 'paid_on' => '2026-09-18', 'then' => 'receipt'])->assertSessionHasNoErrors()->assertRedirect('/panel/tahsilat/belge/1');
        $this->assertSame(['paid', 120000], [$invoice->fresh()->status, $invoice->fresh()->paid_amount]);
        $doc = Document::query()->firstOrFail();
        $this->assertSame(['receipt', 'MKB-2026-000001', 'issued'], [$doc->kind, $doc->number, $doc->status]);
        $this->assertSame('700,00 ₺', $doc->data['amount']);
        $this->assertSame('Acme A.Ş.', $doc->data['customer_name']);
        $this->assertSame('0,00 ₺', $doc->data['remaining_amount']);
        $html = $this->actingAs($finance)->get('/panel/tahsilat/belge/'.$doc->id)->assertOk()->getContent();
        $this->assertStringContainsString('TAHSİLAT MAKBUZU', $html);
        $this->assertStringContainsString('MKB-2026-000001', $html);
        $this->assertStringContainsString('Kart / POS', $html);
        $this->assertStringContainsString('Ofisvio', $html); // işletme adı site marka ayarından
        $this->assertStringContainsString(NumberWords::amount(70000), $html);
        $this->assertStringContainsString('PDF indir', $html);
        $pdf = $this->actingAs($finance)->get('/panel/tahsilat/belge/'.$doc->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->actingAs($finance)->get('/panel/tahsilat/belge/'.$doc->id.'/yazdir')->assertOk()->assertSee('window.print()', false)->assertSee('MKB-2026-000001');

        // Makbuz düzenle: metin alanları; numara/tutar sabit; audit.
        $this->actingAs($finance)->put('/panel/tahsilat/belge/'.$doc->id, ['customer_name' => 'Acme Anonim Şirketi', 'customer_tax_number' => '1234567890', 'description' => 'Sanal ofis Ekim dönemi', 'date' => '18.09.2026', 'note' => 'Teşekkürler'])->assertRedirect('/panel/tahsilat/belge/'.$doc->id)->assertSessionHasNoErrors();
        $this->assertSame(['Acme Anonim Şirketi', '700,00 ₺', 'MKB-2026-000001'], [$doc->fresh()->data['customer_name'], $doc->fresh()->data['amount'], $doc->fresh()->number]);
        $this->assertTrue(AuditLog::query()->where('action', 'document.updated')->where('entity_id', $doc->id)->exists());
        $this->actingAs($finance)->get('/panel/tahsilat/belge/'.$doc->id)->assertOk()->assertSee('Acme Anonim Şirketi')->assertSee('Teşekkürler');

        // Aynı tahsilata ikinci "Makbuz oluştur" yeni numara üretmez (tek geçerli makbuz).
        $second = Payment::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($finance)->post('/panel/tahsilat/tahsilat/'.$second->id.'/makbuz')->assertRedirect('/panel/tahsilat/belge/'.$doc->id);
        $this->assertSame(1, Document::query()->count());

        // Tahsilat iptali: gerekçe zorunlu; kayıt silinmez, fatura yeniden açılır, makbuz iptal, audit + geçmiş.
        $this->actingAs($finance)->from('/panel/tahsilat')->post('/panel/tahsilat/tahsilat/'.$second->id.'/iptal', ['reason' => 'kısa'])->assertSessionHasErrors('reason');
        $this->actingAs($finance)->post('/panel/tahsilat/tahsilat/'.$second->id.'/iptal', ['reason' => 'POS işlemi geri alındı'])->assertRedirect('/panel/tahsilat#tahsilatlar')->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', 'POS işlemi geri alındı'], [$second->fresh()->status, $second->fresh()->cancel_reason]);
        $this->assertSame(['issued', 50000], [$invoice->fresh()->status, $invoice->fresh()->paid_amount]);
        $this->assertSame('cancelled', $doc->fresh()->status);
        $this->assertSame(2, Payment::withoutTenantScope()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'payment.cancelled')->where('entity_id', $second->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'invoice.payment_reversed')->where('entity_id', $invoice->id)->exists());
        $this->actingAs($finance)->get('/panel/tahsilat')->assertOk()->assertSee('POS işlemi geri alındı')->assertSee('İptal');
        $this->actingAs($finance)->get('/panel/tahsilat/belge/'.$doc->id)->assertOk()->assertSee('Bu belge iptal edildi');
        $this->actingAs($finance)->from('/panel/tahsilat')->post('/panel/tahsilat/tahsilat/'.$second->id.'/makbuz')->assertSessionHasErrors('payment');
        // Özet KPI'ları iptali saymaz: bu ay tahsil edilen yalnız nakit 500.
        $this->actingAs($finance)->get('/panel/tahsilat')->assertOk()->assertSee('Bu ay tahsil edilen')->assertSee('500,00 ₺');
        $this->assertSame(50000, app(InvoiceService::class)->dashboard()['revenue_month']);

        // Yetki: finans dışı personel yazamaz; müşteri sahibi tahsilat merkezine giremez.
        $this->actingAs($this->staff('operations_admin'))->post('/panel/tahsilat/tahsilat', ['invoice_id' => $invoice->id, 'amount' => '10', 'method' => 'cash', 'paid_on' => '2026-09-18'])->assertForbidden();
        $this->actingAs($this->staff('operations_admin'))->get('/panel/tahsilat')->assertForbidden();
        $this->actingAs($owner)->withContext($co->organization)->get('/panel/tahsilat/belge/'.$doc->id)->assertForbidden();
    }

    #[Test]
    public function tahsilat_kaydi_kilitli_satirda_dogrulanir_ve_cift_kayit_engellenir(): void
    {
        // Audit F-01: bakiye kontrolü işlem içinde kilitli/güncel satırda; idempotency (referans) ve çift tık penceresi.
        [$co, $owner, $invoice] = $this->invoice(100000);
        $finance = $this->staff('finance_admin');
        $service = app(InvoiceService::class);

        // 1) Bayat model örneğiyle fazla ödeme: A örneği bakiyeyi 1.000 sanırken B üzerinden 700 tahsil edildi → A'dan 500 reddedilir.
        $stale = $invoice->fresh();
        $service->recordPayment($finance, $invoice->fresh(), ['amount' => '700', 'method' => 'transfer', 'paid_on' => '2026-09-18', 'reference' => 'HAVALE-1']);
        $this->assertSame(100000, $stale->outstanding()); // bayat örnek hâlâ eski bakiyeyi görüyor
        try {
            $service->recordPayment($finance, $stale, ['amount' => '500', 'method' => 'transfer', 'paid_on' => '2026-09-18', 'reference' => 'HAVALE-2']);
            $this->fail('Bayat örnek üzerinden bakiye aşımı kabul edildi.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('kalan bakiyeyi', $e->getMessage());
        }
        $this->assertSame(30000, $invoice->fresh()->outstanding());
        $this->assertSame(30000, $stale->outstanding()); // reddedilse de örnek kilitli satırla eşitlendi

        // 2) Aynı referans ikinci kez → reddedilir (webhook tekrarı / çift gönderim); iptal edilen referans yeniden kullanılabilir.
        try {
            $service->recordPayment($finance, $invoice->fresh(), ['amount' => '100', 'method' => 'transfer', 'paid_on' => '2026-09-18', 'reference' => 'HAVALE-1']);
            $this->fail('Aynı referansla ikinci tahsilat kabul edildi.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('HAVALE-1', $e->getMessage());
        }
        $this->assertSame(1, Payment::withoutTenantScope()->count());

        // 3) Referanssız çift tık: aynı tutar/yöntem/tarih 60 sn içinde → reddedilir; pencere geçince ya da referansla → kabul.
        $service->recordPayment($finance, $invoice->fresh(), ['amount' => '100', 'method' => 'cash', 'paid_on' => '2026-09-18']);
        try {
            $service->recordPayment($finance, $invoice->fresh(), ['amount' => '100', 'method' => 'cash', 'paid_on' => '2026-09-18']);
            $this->fail('Çift tık kabul edildi.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('çift kayıt engellendi', $e->getMessage());
        }
        $this->assertSame(2, Payment::withoutTenantScope()->count());
        $service->recordPayment($finance, $invoice->fresh(), ['amount' => '100', 'method' => 'cash', 'paid_on' => '2026-09-18', 'reference' => 'NAKIT-2']);
        Carbon::setTestNow('2026-09-18 10:02:00');
        $service->recordPayment($finance, $invoice->fresh(), ['amount' => '100', 'method' => 'cash', 'paid_on' => '2026-09-18']);
        $this->assertSame(4, Payment::withoutTenantScope()->count());
        $this->assertSame(0, $invoice->fresh()->outstanding());
        $this->assertSame('paid', $invoice->fresh()->status);

        // 4) Ödenmiş faturaya kayıt kilit altında da reddedilir; panel formu çift gönderimde hata mesajı alır (500 yok).
        $this->actingAs($finance)->from('/panel/tahsilat')->post('/panel/tahsilat/tahsilat', ['invoice_id' => $invoice->id, 'amount' => '1', 'method' => 'cash', 'paid_on' => '2026-09-18'])->assertRedirect('/panel/tahsilat')->assertSessionHasErrors();
        $this->assertSame(4, Payment::withoutTenantScope()->count());

        // Audit F-15: append-only defter — fatura kesimi (+) ve 4 tahsilat (−) satırı; kalan sıfır; satır güncellenemez/silinemez;
        // tahsilat iptali yeni (+) satır açar; defter ekranı ledger.view ister.
        $entries = LedgerEntry::withoutTenantScope()->where('invoice_id', $invoice->id)->orderBy('id')->get();
        $this->assertSame(['invoice_issued', 'payment_received', 'payment_received', 'payment_received', 'payment_received'], $entries->pluck('type')->all());
        $this->assertSame(0, $entries->sum('amount'));
        $this->assertSame(0, $entries->last()->balance_after);
        try {
            $entries->first()->forceFill(['amount' => 1])->save();
            $this->fail('Defter satırı güncellendi.');
        } catch (\LogicException) {
        }
        try {
            $entries->first()->delete();
            $this->fail('Defter satırı silindi.');
        } catch (\LogicException) {
        }
        $service->cancelPayment($finance, Payment::withoutTenantScope()->orderBy('id')->firstOrFail(), 'Yanlış fatura');
        $latest = LedgerEntry::withoutTenantScope()->where('invoice_id', $invoice->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame(['payment_reversed', 70000, 70000], [$latest->type, $latest->amount, $latest->balance_after]);
        $this->assertSame(6, LedgerEntry::withoutTenantScope()->where('invoice_id', $invoice->id)->count());
        $this->actingAs($finance)->get('/panel/tahsilat/defter')->assertOk()->assertSee('Muhasebe defteri')->assertSee('Tahsilat iptali')->assertSee('Yanlış fatura');
        $this->actingAs($this->staff('operations_admin'))->get('/panel/tahsilat/defter')->assertForbidden();

        // Düzeltme kaydı (ledger.correction_entry JIT'li): grant yokken 403; gerekçeli süreli erişim sonrası imzalı yeni satır; fatura bakiyesi değişmez; sıfır tutar reddedilir.
        $this->actingAs($finance)->post('/panel/tahsilat/defter/'.$invoice->id.'/duzeltme', ['amount' => '10', 'direction' => 'credit', 'memo' => 'Yuvarlama'])->assertForbidden();
        $this->actingAs($finance)->post('/panel/tahsilat/defter/'.$invoice->id.'/jit', ['reason' => 'Kuruş farkı düzeltmesi', 'ttl_minutes' => 15])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($finance)->from('/panel/tahsilat/defter')->post('/panel/tahsilat/defter/'.$invoice->id.'/duzeltme', ['amount' => '0', 'direction' => 'credit', 'memo' => 'x'])->assertSessionHasErrors();
        $this->actingAs($finance)->post('/panel/tahsilat/defter/'.$invoice->id.'/duzeltme', ['amount' => '0,10', 'direction' => 'credit', 'memo' => 'Yuvarlama'])->assertRedirect()->assertSessionHasNoErrors();
        $correction = LedgerEntry::withoutTenantScope()->where('invoice_id', $invoice->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame(['correction', -10, 'Düzeltme: Yuvarlama'], [$correction->type, $correction->amount, $correction->memo]);
        $this->assertSame(70000, $invoice->fresh()->outstanding(), 'düzeltme fatura bakiyesine dokunmaz');
        $this->assertTrue(AuditLog::query()->where('action', 'ledger.correction')->exists());
        $this->actingAs($finance)->get('/panel/tahsilat/defter')->assertOk()->assertSee('Düzeltme kaydı ekle')->assertSee('Düzeltme: Yuvarlama');
    }

    #[Test]
    public function geciken_odemeler_sekmesi_belgesi_ve_sablon_duzenleme(): void
    {
        [$co, $owner, $invoice] = $this->invoice(120000, '2026-09-01');
        $finance = $this->staff('finance_admin');
        app(InvoiceService::class)->recordPayment($finance, $invoice, ['amount' => '200', 'method' => 'transfer', 'paid_on' => '2026-09-10']);
        app(InvoiceService::class)->markOverdue();
        $this->assertSame('overdue', $invoice->fresh()->status);

        // Mevcut açık fatura listesi (gecikmiş önce) aynen; gecikmiş satırda "Belge oluştur" + "Tahsilat ekle" + "Aç".
        $html = $this->actingAs($finance)->get('/panel/tahsilat')->assertOk()->getContent();
        $this->assertStringContainsString('Acme A.Ş.', $html);
        $this->assertStringContainsString('17 gün gecikti', $html);
        $this->assertStringContainsString('1.000,00 ₺', $html);
        $this->assertStringContainsString('Belge oluştur', $html);
        $this->assertStringContainsString('Tahsilat ekle', $html);
        $this->assertStringContainsString('>Aç</a>', $html);

        // Belge: numara GOB-2026-000001, alanlar; düzenle → önizle → PDF → yazdır akışı.
        $this->actingAs($finance)->post('/panel/tahsilat/fatura/'.$invoice->id.'/gecikme-belgesi')->assertRedirect('/panel/tahsilat/belge/1')->assertSessionHasNoErrors();
        $doc = Document::query()->firstOrFail();
        $this->assertSame(['overdue_notice', 'GOB-2026-000001', '17', '1.200,00 ₺', '200,00 ₺', '1.000,00 ₺', '01.09.2026'], [$doc->kind, $doc->number, $doc->data['days_overdue'], $doc->data['amount'], $doc->data['paid_amount'], $doc->data['remaining_amount'], $doc->data['due_date']]);
        $page = $this->actingAs($finance)->get('/panel/tahsilat/belge/'.$doc->id)->assertOk()->getContent();
        $this->assertStringContainsString('GECİKEN ÖDEME BİLDİRİMİ', $page);
        $this->assertStringContainsString('17 gündür gecikmiştir', $page);
        $this->assertStringContainsString('Gecikme: 17 gün', $page);
        $this->actingAs($finance)->put('/panel/tahsilat/belge/'.$doc->id, ['customer_name' => 'Acme A.Ş.', 'invoice_description' => 'Sanal ofis Ekim (gecikmiş)', 'date' => '18.09.2026', 'note' => 'Son ödeme günü 25.09.2026'])->assertSessionHasNoErrors();
        $this->actingAs($finance)->get('/panel/tahsilat/belge/'.$doc->id)->assertOk()->assertSee('Son ödeme günü 25.09.2026');
        $this->assertStringStartsWith('%PDF', $this->actingAs($finance)->get('/panel/tahsilat/belge/'.$doc->id.'/pdf')->assertOk()->getContent());
        $this->actingAs($finance)->get('/panel/tahsilat/belge/'.$doc->id.'/yazdir')->assertOk()->assertSee('GOB-2026-000001');
        // Ödenmiş/vadesi gelmemiş faturaya belge düzenlenmez.
        [, , $fresh] = ['', '', app(InvoiceService::class)->create($finance, $co, ['description' => 'Yeni', 'subtotal' => '100', 'tax_rate' => 0, 'due_on' => '2026-12-01', 'issue' => true])];
        $this->actingAs($finance)->from('/panel/tahsilat')->post('/panel/tahsilat/fatura/'.$fresh->id.'/gecikme-belgesi')->assertSessionHasErrors('invoice');
        $this->actingAs($finance)->get('/panel/tahsilat')->assertOk()->assertSee('GOB-2026-000001')->assertSee('Geciken ödeme belgesi')->assertSee('Tahsilat makbuzu');

        // Şablon: düzenleme ekranı yer tutucuları ve gerçek kayıtla önizlemeyi gösterir; kaydet → belge çıktısı değişir.
        $editor = $this->actingAs($finance)->get('/panel/tahsilat/sablon/overdue_notice')->assertOk()->getContent();
        $this->assertStringContainsString('{{remaining_amount}}', $editor);
        $this->assertStringContainsString('data-live-preview', $editor);
        $this->assertStringContainsString('son gerçek kayıtla', $editor);
        $this->assertStringContainsString('Acme A.Ş.', $editor);
        $this->actingAs($finance)->from('/panel/tahsilat/sablon/overdue_notice')->put('/panel/tahsilat/sablon/overdue_notice', ['heading' => '', 'subheading' => 'x', 'columns' => ['invoice_number']])->assertSessionHasErrors('heading');
        $this->actingAs($finance)->put('/panel/tahsilat/sablon/overdue_notice', ['logo_url' => '/images/logo.png', 'heading' => 'ÖDEME HATIRLATMA', 'subheading' => 'No {{document_number}}', 'show_business' => 1, 'intro' => 'Sayın {{customer_name}}, kalan {{remaining_amount}} tutarı {{days_overdue}} gündür gecikmiştir.', 'columns' => ['invoice_number', 'remaining_amount'], 'body' => 'Kalan: {{remaining_amount}}', 'signature' => 'Muhasebe', 'stamp' => '', 'footer' => '{{business_name}}', 'accent' => '#aa3333'])
            ->assertRedirect('/panel/tahsilat/sablon/overdue_notice')->assertSessionHasNoErrors();
        $this->assertTrue(AuditLog::query()->where('action', 'document.template_updated')->exists());
        $rendered = app(DocumentService::class)->html($doc->fresh());
        $this->assertStringContainsString('ÖDEME HATIRLATMA', $rendered);
        $this->assertStringContainsString('No GOB-2026-000001', $rendered);
        $this->assertStringContainsString('kalan 1.000,00 ₺ tutarı 17 gündür', $rendered);
        $this->assertStringContainsString('#aa3333', $rendered);
        $this->assertStringContainsString('Muhasebe', $rendered);
        $this->assertStringNotContainsString('Vade</th>', $rendered); // yalnız seçili sütunlar
        $this->assertStringContainsString('src="/images/logo.png"', $rendered);
        // Bilinmeyen yer tutucu olduğu gibi kalır (yazım hatası görünür); geçerli olan değişir.
        $this->assertSame('Acme A.Ş. · {{bilinmeyen}}', app(DocumentService::class)->substitute('{{customer_name}} · {{bilinmeyen}}', ['customer_name' => 'Acme A.Ş.']));
        // Şablon yazma invoice.issue ister; görüntüleme invoice.view.
        $this->actingAs($this->staff('operations_admin'))->put('/panel/tahsilat/sablon/receipt', ['heading' => 'X'])->assertForbidden();
        // invoice.view taşıyıp invoice.issue taşımayan rol (muhasebeci gibi) yalnız görür: super_admin yazabildiği için finans dışı personel 403 (yukarıda).
    }

    #[Test]
    public function tutar_yaziyla(): void
    {
        $this->assertSame('bin iki yüz lira sıfır kuruş', NumberWords::amount(120000));
        $this->assertSame('üç bin beş yüz kırk iki lira yetmiş beş kuruş', NumberWords::amount(354275));
        $this->assertSame('bir milyon lira sıfır kuruş', NumberWords::amount(100000000));
        $this->assertSame('sıfır euro on sent', NumberWords::amount(10, 'EUR'));
    }
}
