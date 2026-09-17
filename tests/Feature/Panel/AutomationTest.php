<?php

namespace Tests\Feature\Panel;

use App\Enums\BookingStatus;
use App\Enums\CompanyStatus;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\Room;
use App\Models\Subscription;
use App\Services\BookingService;
use App\Services\InvoiceService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\SubscriptionService;
use Database\Seeders\NotificationRuleSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit P1-5/7/8 — Otomasyon: üyelik otomatik yenileme (+ sistem faturası), bitiş/vade hatırlatmaları
 * (tek seferlik, ayardan), gecikme bildirimi, ödeme teyidi, uzun gecikmede askıya alma (ayar kapalıysa
 * hiçbir şey), etkinlik kaydı ve franchise başvurusu olayları; müşteri muhatabı = şirket sahibi.
 */
class AutomationTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
        $this->seed(NotificationRuleSeeder::class);
        Carbon::setTestNow('2026-09-17 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<int, string> */
    private function events(string $entityType, int $entityId, string $channel = 'email'): array
    {
        return NotificationLog::query()->where('entity_type', $entityType)->where('entity_id', $entityId)->where('channel', $channel)->pluck('event')->sort()->values()->all();
    }

    #[Test]
    public function uyelik_otomatik_yenilenir_fatura_keser_ve_bildirir(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $acmeCo);
        $finance = $this->staff('finance_admin');
        $svc = app(SubscriptionService::class);
        $plan = $svc->createPlan($finance, ['name' => 'Coworking Aylık', 'price' => '3500', 'period' => 'monthly']);

        // Bitişe 7 gün: expiring bildirimi bir kez (ikinci koşuda tekrar yok).
        $sub = $svc->create($finance, $acmeCo, $plan, ['starts_on' => '2026-08-25', 'months' => 1, 'auto_renew' => true]); // bitiş 24 Eylül
        $this->assertSame('2026-09-24', $sub->ends_on->toDateString());
        $this->artisan('subscriptions:remind-expiring')->expectsOutputToContain('1 üyelik için bildirim')->assertExitCode(0);
        $this->artisan('subscriptions:remind-expiring')->expectsOutputToContain('Bildirilecek üyelik yok')->assertExitCode(0);
        $this->assertSame(['subscription.expiring'], $this->events('subscription', $sub->id));
        $log = NotificationLog::query()->where('event', 'subscription.expiring')->where('channel', 'email')->firstOrFail();
        $this->assertSame($owner->email, $log->recipient); // muhatap = şirket sahibi
        $this->assertStringContainsString('Coworking Aylık', $log->body);
        $this->assertStringContainsString('3.500,00 ₺', $log->body);

        // Bitiş geçti → yenile: yeni dönem 25 Eylül–24 Ekim, fatura yayınlandı (KDV ayardan %20), renewed + invoice.issued bildirimi.
        Carbon::setTestNow('2026-09-25 00:06:00');
        $this->artisan('subscriptions:renew')->expectsOutputToContain('1 üyelik yenilendi')->assertExitCode(0);
        $this->artisan('subscriptions:expire')->expectsOutputToContain('Süresi dolan üyelik yok')->assertExitCode(0);
        $sub = $sub->fresh();
        $this->assertSame(['active', '2026-09-25', '2026-10-24', 1, null], [$sub->status, $sub->starts_on->toDateString(), $sub->ends_on->toDateString(), $sub->renewal_count, $sub->expiring_notice_sent_at]);
        $invoice = Invoice::withoutTenantScope()->where('subscription_id', $sub->id)->firstOrFail();
        $this->assertSame(['issued', 350000, 420000, 'F-2026-000001'], [$invoice->status, $invoice->subtotal, $invoice->total, $invoice->number]);
        $this->assertStringContainsString('25.09.2026 – 24.10.2026', $invoice->description);
        $this->assertSame(['subscription.expiring', 'subscription.renewed'], $this->events('subscription', $sub->id));
        $this->assertSame(['invoice.issued'], $this->events('invoice', $invoice->id));
        $this->assertTrue(AuditLog::query()->where('action', 'subscription.renewed')->exists());

        // Yenileme kapalı üyelik: yenilenmez, süresi dolar ve bildirilir.
        $manual = $svc->create($finance, $acmeCo, $plan, ['starts_on' => '2026-08-01', 'months' => 1, 'auto_renew' => false]);
        Subscription::withoutTenantScope()->whereKey($manual->id)->update(['ends_on' => '2026-09-20']); // çakışmasız test verisi
        $this->artisan('subscriptions:renew')->expectsOutputToContain('Yenilenecek üyelik yok');
        $this->artisan('subscriptions:expire')->expectsOutputToContain('1 üyeliğin süresi doldu');
        $this->assertSame('expired', $manual->fresh()->status);
        $this->assertSame(['subscription.expired'], $this->events('subscription', $manual->id));

        // Panelden açılan üyelikte "ilk dönem faturası" seçeneği: fatura hemen yayınlanır (aktörlü).
        $this->actingAs($finance)->post('/panel/uyelikler', ['company_id' => $acmeCo->id, 'plan_id' => $plan->id, 'starts_on' => '2026-11-01', 'months' => 1, 'auto_renew' => 1, 'issue_invoice' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $first = Subscription::withoutTenantScope()->orderByDesc('id')->firstOrFail();
        $firstInvoice = Invoice::withoutTenantScope()->where('subscription_id', $first->id)->firstOrFail();
        $this->assertSame(['issued', $finance->id], [$firstInvoice->status, $firstInvoice->created_by]);
        $this->assertSame(['invoice.issued'], $this->events('invoice', $firstInvoice->id));

        // Yenilemede fatura ayarı kapalıysa fatura kesilmez.
        app(SettingsService::class)->set($finance, 'finance.auto_invoice_on_renewal', false);
        Carbon::setTestNow('2026-10-25 00:06:00');
        $this->artisan('subscriptions:renew')->expectsOutputToContain('1 üyelik yenilendi');
        $this->assertSame(1, Invoice::withoutTenantScope()->where('subscription_id', $sub->id)->count());
        $this->assertSame(2, $sub->fresh()->renewal_count);
    }

    #[Test]
    public function tahsilat_otomasyonu_hatirlatma_gecikme_odeme_ve_askiya_alma(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $acmeCo);
        $finance = $this->staff('finance_admin');
        $settings = app(SettingsService::class);
        $invoices = app(InvoiceService::class);

        // Vade 21 Eylül (3 gün kala hatırlat): 17'sinde (4 gün) henüz değil, 18'inde bir kez.
        $inv = $invoices->create($finance, $acmeCo, ['description' => 'Eylül', 'subtotal' => '1000', 'tax_rate' => 0, 'due_on' => '2026-09-21', 'issue' => true]);
        $this->assertSame(['invoice.issued'], $this->events('invoice', $inv->id));
        $this->assertSame($owner->email, NotificationLog::query()->where('event', 'invoice.issued')->where('channel', 'email')->value('recipient'));
        $this->artisan('invoices:remind-due')->expectsOutputToContain('Hatırlatılacak fatura yok');
        Carbon::setTestNow('2026-09-18 08:10:00');
        $this->artisan('invoices:remind-due')->expectsOutputToContain('1 fatura için hatırlatma');
        $this->artisan('invoices:remind-due')->expectsOutputToContain('Hatırlatılacak fatura yok');
        $this->assertSame(['invoice.due_soon', 'invoice.issued'], $this->events('invoice', $inv->id));
        $this->assertNotNull($inv->fresh()->due_reminder_sent_at);

        // Vade geçti → gecikmiş + bildirim; askıya alma kapalı (0) → şirket aktif kalır.
        $acmeCo->forceFill(['status' => CompanyStatus::ACTIVE])->save(); // fixture: askıya alma yalnız AKTİF şirkete uygulanır
        Carbon::setTestNow('2026-09-22 00:20:00');
        $this->artisan('invoices:mark-overdue')->expectsOutputToContain('1 fatura gecikmiş');
        $this->artisan('finance:suspend-overdue')->expectsOutputToContain('Askıya alınacak şirket yok');
        $this->assertSame(['invoice.due_soon', 'invoice.issued', 'invoice.overdue'], $this->events('invoice', $inv->id));
        $this->assertSame(CompanyStatus::ACTIVE, $acmeCo->fresh()->status);

        // Ayar 10 gün: 9. günde değil, 11. günde askıya alınır (durum geçişi + audit + bildirim); ikinci koşuda tekrar yok.
        $settings->set($finance, 'finance.suspend_after_overdue_days', 10);
        Carbon::setTestNow('2026-09-30 00:25:00');
        $this->artisan('finance:suspend-overdue')->expectsOutputToContain('Askıya alınacak şirket yok');
        Carbon::setTestNow('2026-10-02 00:25:00');
        $this->artisan('finance:suspend-overdue')->expectsOutputToContain('1 şirket askıya alındı');
        $this->assertSame(CompanyStatus::SUSPENDED, $acmeCo->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'company.suspended_overdue')->where('entity_id', $acmeCo->id)->exists());
        $this->assertSame(['company.suspended'], $this->events('company', $acmeCo->id));
        $this->artisan('finance:suspend-overdue')->expectsOutputToContain('Askıya alınacak şirket yok');

        // Ödeme → invoice.paid müşteriye.
        $invoices->recordPayment($finance, $inv->fresh(), ['amount' => '1000', 'method' => 'transfer', 'paid_on' => '2026-10-02']);
        $this->assertSame(['invoice.due_soon', 'invoice.issued', 'invoice.overdue', 'invoice.paid'], $this->events('invoice', $inv->id));
    }

    #[Test]
    public function onayli_rezervasyon_fatura_keser_iptalde_odenmemis_fatura_iptal_olur(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $acmeCo);
        $ops = $this->staff('operations_admin');
        $bookings = app(BookingService::class);
        $kadikoy = Location::create(['name' => 'Kadıköy', 'slug' => 'kadikoy', 'city' => 'İstanbul', 'region' => 'Anadolu', 'is_active' => true, 'is_published' => true]);
        $room = Room::create(['location_id' => $kadikoy->id, 'name' => 'Toplantı 1', 'kind' => 'meeting', 'capacity' => 6, 'hourly_rate' => 40000, 'open_from' => '09:00', 'open_until' => '18:00', 'slot_minutes' => 60, 'max_hours' => 4])->refresh(); // is_active DB varsayılanı

        // Şirket rezervasyonu (onay bekler): fatura yok; onaylanınca 2 saat × 400 ₺ = 800 ₺ + KDV faturası booking_id ile.
        $booking = $bookings->book($owner, $acmeCo, $room, ['date' => '2026-09-19', 'start' => '10:00', 'hours' => 2]);
        $this->assertSame(BookingStatus::PENDING_APPROVAL, $booking->status);
        $this->assertSame(0, Invoice::withoutTenantScope()->where('booking_id', $booking->id)->count());
        $bookings->approve($ops, $booking->fresh());
        $invoice = Invoice::withoutTenantScope()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(['issued', 80000, 96000], [$invoice->status, $invoice->subtotal, $invoice->total]);
        $this->assertStringContainsString($booking->reference, $invoice->description);
        $this->assertSame(['invoice.issued'], $this->events('invoice', $invoice->id));
        $this->actingAs($this->staff('finance_admin'))->get("/panel/faturalar/{$invoice->id}")->assertOk()->assertSee($booking->reference); // operasyon invoice.view taşımaz

        // İptal → ödenmemiş fatura sistemce iptal (audit aktörsüz); tekrar onay/iptal döngüsü ikinci fatura açmaz.
        $bookings->cancel($ops, $booking->fresh(), 'Müşteri vazgeçti', true);
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertStringContainsString('otomatik', (string) $invoice->fresh()->cancel_reason);

        // Vitrin (şirketsiz) talep: onaylansa da fatura yok — tenant yok.
        $public = $bookings->book(null, null, $room, ['date' => '2026-09-20', 'start' => '10:00', 'hours' => 1, 'customer_name' => 'Ayşe', 'customer_email' => 'ayse@ornek.com', 'customer_phone' => '+905321112233', 'source' => 'site']);
        $bookings->approve($ops, $public->fresh());
        $this->assertSame(0, Invoice::withoutTenantScope()->where('booking_id', $public->id)->count());

        // Ayar kapalı: onay fatura üretmez.
        app(SettingsService::class)->set($ops, 'finance.auto_invoice_bookings', false);
        $second = $bookings->book($owner, $acmeCo, $room, ['date' => '2026-09-21', 'start' => '10:00', 'hours' => 1]);
        $bookings->approve($ops, $second->fresh());
        $this->assertSame(0, Invoice::withoutTenantScope()->where('booking_id', $second->id)->count());
    }

    #[Test]
    public function etkinlik_kaydi_ve_franchise_basvurusu_bildirim_uretir(): void
    {
        $event = Event::create(['title' => 'Kahvaltı', 'slug' => 'kahvalti', 'starts_at' => '2026-09-25 09:00', 'ends_at' => '2026-09-25 11:00', 'is_published' => true, 'registration_open' => true]);
        $this->post('/etkinlik/kahvalti/kayit', ['name' => 'Ayşe', 'email' => 'ayse@ornek.com', 'kvkk' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $log = NotificationLog::query()->where('event', 'event.registered')->where('channel', 'email')->firstOrFail();
        $this->assertSame('ayse@ornek.com', $log->recipient);
        $this->assertStringContainsString('Kahvaltı', $log->body);

        // CRM grubu alıcısı (e-posta) tanımlı olmalı; alıcılar yalnız DB'den.
        app(NotificationService::class)->saveRecipient($this->staff('system_admin'), ['group' => 'crm', 'channel' => 'email', 'address' => 'crm@ofisvio.test', 'name' => 'CRM']);
        $this->post('/franchise', ['name' => 'Mehmet', 'email' => 'm@ornek.com', 'city' => 'İzmir', 'kvkk' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(NotificationLog::query()->where('event', 'franchise.applied')->exists());
        $this->assertStringContainsString('İzmir', (string) NotificationLog::query()->where('event', 'franchise.applied')->value('body'));
    }
}
