<?php

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\SettingsService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 39c — Ödemeler & faturalandırma + tahsilat takibi: taslak → yayın (numara ayardan,
 * KDV/vade), kısmi/tam tahsilat (bakiye, durum), iptal kuralları, gecikme zamanlayıcısı,
 * dashboard/tahsilat/rozet toplamları, müşteri tarafı (taslak görünmez, tenant sınırı), izinler.
 */
class InvoiceTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Carbon::setTestNow('2026-09-17 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function fatura_yasam_dongusu_tahsilat_iptal_ve_musteri_gorunumu(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $acmeCo);
        $beta = $this->organization('Beta');
        $betaOwner = $this->owner($beta, $this->company($beta, 'Beta Ltd.'));
        $finance = $this->staff('finance_admin');  // invoice.view/issue/cancel + payment_allocation.manage
        $ops = $this->staff('operations_admin');   // yok
        app(SettingsService::class)->set($finance, 'finance.invoice_prefix', 'OF');

        $this->actingAs($ops)->get('/panel/faturalar')->assertForbidden();
        $this->actingAs($ops)->get('/panel/tahsilat')->assertForbidden();
        $this->actingAs($finance)->get('/panel/faturalar')->assertOk()->assertSee('Bu sekmede fatura yok')->assertSee('Yeni fatura');
        $this->actingAs($finance)->get('/panel/faturalar/yeni?sirket='.$acmeCo->id)->assertOk()->assertSee('Acme A.Ş.')->assertSee('value="20"', false); // varsayılan KDV ayardan

        // Taslak (yayınsız): numara yok, müşteri görmez; sıfır tutarlı yayın reddedilir.
        $this->actingAs($finance)->post('/panel/faturalar', ['company_id' => $acmeCo->id, 'description' => 'Boş', 'subtotal' => 0, 'tax_rate' => 20, 'issue' => 0])->assertRedirect();
        $draft = Invoice::withoutTenantScope()->firstOrFail();
        $this->assertSame(['draft', null, 0], [$draft->status, $draft->number, $draft->total]);
        $this->actingAs($finance)->from("/panel/faturalar/{$draft->id}")->post("/panel/faturalar/{$draft->id}/yayinla")->assertSessionHasErrors('invoice');
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/faturalar")->assertOk()->assertSee('Henüz fatura yok');

        // Yayınlı fatura: 1000 + %20 = 1200, numara OF-2026-000001, vade formdan.
        $this->actingAs($finance)->post('/panel/faturalar', ['company_id' => $acmeCo->id, 'description' => 'Sanal Ofis — Ekim', 'subtotal' => '1000', 'tax_rate' => 20, 'due_on' => '2026-09-24', 'issue' => 1] /* ₺ büyük birim */)
            ->assertRedirect()->assertSessionHasNoErrors();
        $inv = Invoice::withoutTenantScope()->where('status', 'issued')->firstOrFail();
        $this->assertSame(['OF-2026-000001', 120000, 20000, /* kuruş */ '2026-09-17', '2026-09-24'], [$inv->number, $inv->total, $inv->tax_amount, $inv->issued_on->toDateString(), $inv->due_on->toDateString()]);
        $this->assertSame(['invoice.created', 'invoice.created', 'invoice.issued'], AuditLog::query()->where('entity_type', 'invoice')->pluck('action')->sort()->values()->all());

        // Liste + detay + KPI; müşteri görür (taslak hariç), başka organizasyon 404, müşteri tahsilat kaydedemez.
        $this->actingAs($finance)->get('/panel/faturalar')->assertOk()->assertSee('OF-2026-000001')->assertSee('<span class="k">Bekleyen tahsilat</span><span class="v">1.200,00 ₺</span>', false);
        $this->actingAs($finance)->get("/panel/faturalar/{$inv->id}")->assertOk()->assertSee('Tahsilat kaydet')->assertSee('kalan <b>1.200,00 ₺', false);
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/faturalar")->assertOk()->assertSee('OF-2026-000001')->assertDontSee('Boş');
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/faturalar/{$inv->id}")->assertOk()->assertSee('1.200,00 ₺')->assertDontSee('Tahsilat kaydet');
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/faturalar/{$draft->id}")->assertNotFound();
        $this->actingAs($betaOwner)->withContext($beta)->get("/panel/sirketler/{$acmeCo->id}/faturalar")->assertNotFound();
        $this->actingAs($owner)->withContext($acme)->post("/panel/faturalar/{$inv->id}/tahsilat", ['amount' => 1200, 'method' => 'transfer', 'paid_on' => '2026-09-17'])->assertForbidden();

        // Kısmi tahsilat → bakiye 700, durum yayınlı; fazla ödeme reddedilir; kısmi ödemeli fatura iptal edilemez.
        $this->actingAs($finance)->post("/panel/faturalar/{$inv->id}/tahsilat", ['amount' => '500,00', 'method' => 'transfer', 'paid_on' => '2026-09-17', 'reference' => 'DEK-1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['issued', 50000, 70000], [$inv->fresh()->status, $inv->fresh()->paid_amount, $inv->fresh()->outstanding()]);
        $this->actingAs($finance)->from("/panel/faturalar/{$inv->id}")->post("/panel/faturalar/{$inv->id}/tahsilat", ['amount' => 800, 'method' => 'card', 'paid_on' => '2026-09-17'])->assertSessionHasErrors('amount');
        // İptal JIT'li: grant yokken 403; gerekçeli JIT isteği → grant → kısmi ödemeli fatura yine iptal edilemez (kural).
        $this->actingAs($finance)->post("/panel/faturalar/{$inv->id}/iptal", ['reason' => 'Hata'])->assertForbidden();
        $this->actingAs($finance)->get("/panel/faturalar/{$inv->id}")->assertOk()->assertSee('İptal için erişim iste')->assertDontSee('Faturayı iptal et');
        $this->actingAs($finance)->from("/panel/faturalar/{$inv->id}")->post("/panel/faturalar/{$inv->id}/jit", ['reason' => 'kısa', 'ttl_minutes' => 30])->assertSessionHasErrors('reason');
        $this->actingAs($finance)->post("/panel/faturalar/{$inv->id}/jit", ['reason' => 'Müşteri talebiyle düzeltme faturası kesilecek', 'ttl_minutes' => 30])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($finance)->get("/panel/faturalar/{$inv->id}")->assertOk()->assertSee('Faturayı iptal et');
        $this->actingAs($finance)->from("/panel/faturalar/{$inv->id}")->post("/panel/faturalar/{$inv->id}/iptal", ['reason' => 'Hata'])->assertSessionHasErrors('reason');
        $this->assertSame('issued', $inv->fresh()->status);
        $this->actingAs($ops)->post("/panel/faturalar/{$inv->id}/jit", ['reason' => 'Operasyon iptal etmek istiyor', 'ttl_minutes' => 30])->assertForbidden();

        // Kalanı tahsil → paid; dashboard günlük/aylık ciro 1200; müşteri ödemeleri görür.
        $this->actingAs($finance)->post("/panel/faturalar/{$inv->id}/tahsilat", ['amount' => '700.00', 'method' => 'card', 'paid_on' => '2026-09-17'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['paid', 120000], [$inv->fresh()->status, $inv->fresh()->paid_amount]);
        $this->assertNotNull($inv->fresh()->paid_at);
        $this->assertSame(2, Payment::withoutTenantScope()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'invoice.paid')->exists());
        $html = $this->actingAs($finance)->withContext($acme)->get('/panel')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="k">Günlük ciro</span><span class="v">1.200,00 ₺</span>', $html);
        $this->assertStringContainsString('<span class="k">Aylık ciro</span><span class="v">1.200,00 ₺</span>', $html);
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/faturalar/{$inv->id}")->assertOk()->assertSee('DEK-1')->assertSee('Ödendi');

        // Taslak iptal (JIT grant fatura başına: taslak için ayrı istek); ödenmiş fatura grant olsa da iptal edilemez.
        $this->actingAs($finance)->post("/panel/faturalar/{$draft->id}/iptal", ['reason' => 'Yanlış açıldı'])->assertForbidden();
        $this->actingAs($finance)->post("/panel/faturalar/{$draft->id}/jit", ['reason' => 'Yanlış açılan taslak kapatılacak', 'ttl_minutes' => 15])->assertRedirect();
        $this->actingAs($finance)->post("/panel/faturalar/{$draft->id}/iptal", ['reason' => 'Yanlış açıldı'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $draft->fresh()->status);
        $this->actingAs($finance)->from("/panel/faturalar/{$inv->id}")->post("/panel/faturalar/{$inv->id}/iptal", ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->actingAs($this->staff('system_admin'))->get('/panel/denetim?tur=jit')->assertOk()->assertSee('invoice.cancel');

        // Numara sırası (audit H-2): iptal edilen numara korunur, sıra geri gitmez; yeni yıl sıfırdan başlar.
        $this->actingAs($finance)->post('/panel/faturalar', ['company_id' => $acmeCo->id, 'description' => 'Kasım', 'subtotal' => '10', 'tax_rate' => 0, 'issue' => 1])->assertRedirect();
        $this->assertSame('OF-2026-000002', Invoice::withoutTenantScope()->where('description', 'Kasım')->firstOrFail()->number);
        $this->assertSame(2, (int) DB::table('invoice_sequences')->where('year', 2026)->value('last'));
        Carbon::setTestNow('2027-01-05 09:00:00');
        $this->actingAs($finance)->post('/panel/faturalar', ['company_id' => $acmeCo->id, 'description' => 'Ocak', 'subtotal' => '10', 'tax_rate' => 0, 'issue' => 1])->assertRedirect();
        $this->assertSame('OF-2027-000001', Invoice::withoutTenantScope()->where('description', 'Ocak')->firstOrFail()->number);
    }

    #[Test]
    public function gecikme_zamanlayicisi_rozet_ve_tahsilat_takibi(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $finance = $this->staff('finance_admin');
        $plan = app(SubscriptionService::class)->createPlan($finance, ['name' => 'Coworking', 'price' => 3000, 'period' => 'monthly']);
        $sub = app(SubscriptionService::class)->create($finance, $acmeCo, $plan, ['starts_on' => '2026-09-01', 'months' => 1]); // bitiş 30 Eylül → 13 gün

        // Vadesi dün geçmiş yayınlı fatura + vadesi 5 gün sonra olan fatura (üyeliğe bağlı).
        $this->actingAs($finance)->post('/panel/faturalar', ['company_id' => $acmeCo->id, 'description' => 'Eylül', 'subtotal' => 1000, 'tax_rate' => 0, 'due_on' => '2026-09-16', 'issue' => 1])->assertRedirect();
        $this->actingAs($finance)->post('/panel/faturalar', ['company_id' => $acmeCo->id, 'description' => 'Ekim', 'subtotal' => 3000, 'tax_rate' => 0, 'due_on' => '2026-09-22', 'subscription_id' => $sub->id, 'issue' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $late = Invoice::withoutTenantScope()->where('description', 'Eylül')->firstOrFail();
        $this->assertSame('F-2026-000001', $late->number); // varsayılan önek ayardan

        // Zamanlayıcı: yalnız vadesi geçen overdue (audit); rozet 1; dashboard gecikmiş 1 / 1.000 ₺, bekleyen 4.000 ₺.
        $this->artisan('invoices:mark-overdue')->expectsOutputToContain('1 fatura gecikmiş')->assertExitCode(0);
        $this->assertSame('overdue', $late->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'invoice.overdue')->where('entity_id', $late->id)->exists());
        $html = $this->actingAs($finance)->withContext($acme)->get('/panel')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<span class="t">Tahsilat &amp; üyelik takibi</span>\s*<span class="c c" aria-label="1 bekleyen">1</span>~', $html);
        $this->assertStringContainsString('<span class="k">Gecikmiş ödeme</span><span class="v">1</span>', $html);
        $this->assertStringContainsString('<span class="k">Bekleyen tahsilat</span><span class="v">4.000,00 ₺</span>', $html);
        $this->assertStringContainsString('Gecikmiş ödemeler', $html);
        $this->assertStringContainsString('1 gün gecikti', $html);

        // Tahsilat takibi: gecikmiş önce, 5 gün kaldı, üyelik bitişi 13 gün, aylık seri.
        $page = $this->actingAs($finance)->get('/panel/tahsilat')->assertOk()->getContent();
        $this->assertStringContainsString('1 gün gecikti', $page);
        $this->assertStringContainsString('5 gün kaldı', $page);
        $this->assertStringContainsString('13 gün', $page);
        $this->assertStringContainsString('2026-09', $page);
        $this->assertLessThan(strpos($page, 'Ekim'), strpos($page, 'Eylül'), 'Gecikmiş fatura önce listelenmeli.');
        $this->actingAs($finance)->get('/panel/faturalar?sekme=overdue')->assertOk()->assertSee('Eylül')->assertDontSee('Ekim');
        $this->actingAs($finance)->get('/panel/faturalar?q=ekim')->assertOk()->assertSee('Ekim')->assertDontSee('Eylül');
        $this->actingAs($finance)->get('/panel/raporlar?sekme=tahsilat')->assertOk()->assertSee('Aylık tahsilat');
    }
}
