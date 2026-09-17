<?php

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 39b — Üyelikler & paketler: paket CRUD (finans), üyelik aç/yenile/iptal (durum yalnız
 * serviste, audit), bitiş takibi (rozet + dashboard + zamanlayıcı), müşteri tarafı salt okunur
 * ve tenant sınırı, izinler (operations göremez, finans yazar).
 */
class SubscriptionTest extends TestCase
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
    public function paket_ve_uyelik_yasam_dongusu_izin_ve_tenant_siniri(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $acmeCo);
        $beta = $this->organization('Beta');
        $betaOwner = $this->owner($beta, $this->company($beta, 'Beta Ltd.'));
        $finance = $this->staff('finance_admin');   // subscription.view + manage
        $ops = $this->staff('operations_admin');    // yok

        // Paket: finans oluşturur; operasyon görmez.
        $this->actingAs($ops)->get('/panel/paketler')->assertForbidden();
        $this->actingAs($ops)->post('/panel/paketler', ['name' => 'X', 'price' => 1, 'period' => 'monthly'])->assertForbidden();
        $this->actingAs($finance)->get('/panel/paketler')->assertOk()->assertSee('Henüz paket yok')->assertSee('Yeni paket');
        $this->actingAs($finance)->post('/panel/paketler', ['name' => 'Sanal Ofis Standart', 'summary' => 'Yasal adres + posta', 'features' => "Yasal adres\nPosta bildirimi", 'price' => 990, 'period' => 'monthly', 'is_active' => 1, 'sort_order' => 1])
            ->assertRedirect('/panel/paketler')->assertSessionHasNoErrors();
        $this->actingAs($finance)->from('/panel/paketler/yeni')->post('/panel/paketler', ['name' => 'Bozuk', 'price' => 10, 'period' => 'weekly'])->assertSessionHasErrors('period');
        $plan = Plan::query()->where('slug', 'sanal-ofis-standart')->firstOrFail();
        $this->assertSame(['Yasal adres', 'Posta bildirimi'], $plan->featureList());
        $this->assertTrue(AuditLog::query()->where('action', 'plan.created')->exists());
        $this->actingAs($finance)->get('/panel/paketler')->assertOk()->assertSee('Sanal Ofis Standart')->assertSee('990,00 ₺');

        // Menü: finans "Üyelikler & paketler" görür, operasyon görmez.
        $this->assertStringContainsString('Üyelikler &amp; paketler', $this->actingAs($finance)->get('/panel/uyelikler')->assertOk()->getContent());
        $this->actingAs($ops)->get('/panel/uyelikler')->assertForbidden();

        // Üyelik aç: 12 ay, tutar anlık görüntü; çakışan ikinci üyelik reddedilir.
        $this->actingAs($finance)->post('/panel/uyelikler', ['company_id' => $acmeCo->id, 'plan_id' => $plan->id, 'starts_on' => '2026-09-01', 'months' => 12, 'auto_renew' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $sub = Subscription::withoutTenantScope()->firstOrFail();
        $this->assertSame(['active', '2026-09-01', '2027-08-31', 99000, 'monthly'] /* kuruş */, [$sub->status, $sub->starts_on->toDateString(), $sub->ends_on->toDateString(), $sub->price, $sub->period]);
        $this->actingAs($finance)->from('/panel/uyelikler/yeni')->post('/panel/uyelikler', ['company_id' => $acmeCo->id, 'plan_id' => $plan->id, 'starts_on' => '2026-10-01', 'months' => 1])->assertSessionHasErrors('plan_id');
        $this->assertTrue(AuditLog::query()->where('action', 'subscription.created')->exists());

        // Paket fiyatı değişir → üyelik tutarı değişmez; üyeliği olan paket silinemez.
        $this->actingAs($finance)->put("/panel/paketler/{$plan->id}", ['name' => 'Sanal Ofis Standart', 'price' => 1190, 'period' => 'monthly', 'is_active' => 1])->assertRedirect();
        $this->assertSame(99000, $sub->fresh()->price);
        $this->actingAs($finance)->from('/panel/paketler')->delete("/panel/paketler/{$plan->id}")->assertSessionHasErrors('plan');
        $this->assertNotNull($plan->fresh());

        // Liste + detay + dashboard sayacı: aktif 1, bitişi yaklaşan 0, MRR 990.
        $this->actingAs($finance)->get('/panel/uyelikler')->assertOk()->assertSee('Acme A.Ş.')->assertSee('<span class="k">Aktif üyelik</span><span class="v">1</span>', false)->assertSee('990,00 ₺');
        $this->actingAs($finance)->get("/panel/uyelikler/{$sub->id}")->assertOk()->assertSee('Acme A.Ş. · Sanal Ofis Standart')->assertSee('Yenile')->assertSee('paket bugün 1.190,00 ₺');
        $this->actingAs($finance)->get('/panel/uyelikler/999')->assertNotFound();

        // Müşteri tarafı: sahibi görür (salt okunur), başka organizasyon 404, personel listesi yasak.
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}")->assertOk()->assertSee('Üyelik');
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/uyelik")->assertOk()->assertSee('Sanal Ofis Standart')->assertSee('Aktif')->assertDontSee('/yenile');
        $this->actingAs($betaOwner)->withContext($beta)->get("/panel/sirketler/{$acmeCo->id}/uyelik")->assertNotFound();
        $this->actingAs($owner)->withContext($acme)->get('/panel/uyelikler')->assertForbidden();

        // Yenile: bitişten itibaren 12 ay, güncel fiyat; iptal: yalnız gerekçeyle, sonra yenilenmez.
        $this->actingAs($finance)->post("/panel/uyelikler/{$sub->id}/yenile", ['months' => 12])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['2028-08-31', 119000], [$sub->fresh()->ends_on->toDateString(), $sub->fresh()->price]);
        $this->actingAs($finance)->from("/panel/uyelikler/{$sub->id}")->post("/panel/uyelikler/{$sub->id}/iptal", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($finance)->post("/panel/uyelikler/{$sub->id}/iptal", ['reason' => 'Taşınma'])->assertRedirect();
        $this->assertSame('cancelled', $sub->fresh()->status);
        $this->actingAs($finance)->from("/panel/uyelikler/{$sub->id}")->post("/panel/uyelikler/{$sub->id}/yenile", ['months' => 1])->assertSessionHasErrors('months');
        $this->assertSame(['subscription.cancelled', 'subscription.created', 'subscription.renewed'], AuditLog::query()->where('entity_type', 'subscription')->pluck('action')->sort()->values()->all());
    }

    #[Test]
    public function bitis_takibi_rozet_dashboard_ve_zamanlayici(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $finance = $this->staff('finance_admin');
        $svc = app(SubscriptionService::class);
        $plan = $svc->createPlan($finance, ['name' => 'Coworking Aylık', 'price' => 3500, 'period' => 'monthly']);

        // Bitişi 10 gün sonra olan aktif üyelik + bitişi dün geçmiş aktif üyelik (henüz zamanlayıcı koşmadı).
        $soon = $svc->create($finance, $acmeCo, $plan, ['starts_on' => '2026-08-28', 'months' => 1]);   // 27 Eylül
        $this->assertSame('2026-09-27', $soon->ends_on->toDateString());
        $late = Subscription::withoutTenantScope()->create(['company_id' => $acmeCo->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_on' => '2026-08-01', 'ends_on' => '2026-09-16', 'price' => 3500, 'period' => 'monthly']);

        // Rozet (menü) 1: yalnız bugünden ileri 30 gün; dashboard KPI + kart.
        $html = $this->actingAs($finance)->withContext($acme)->get('/panel')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<span class="t">Üyelikler &amp; paketler</span>\s*<span class="c w" aria-label="1 bekleyen">1</span>~', $html);
        $this->assertStringContainsString('<span class="k">Üyelik bitişi (30 gün)</span><span class="v">1</span>', $html);
        $this->assertStringContainsString('Yaklaşan üyelik bitişleri', $html);
        $this->assertStringContainsString('10 gün', $html);
        $this->actingAs($finance)->get('/panel/uyelikler?sekme=expiring')->assertOk()->assertSee('Coworking Aylık')->assertSee('10 gün');
        $this->actingAs($finance)->get('/panel/uyelikler?sekme=active')->assertOk()->assertSee('1 gün geçti');

        // Zamanlayıcı: bitişi geçen expired (audit, aktör yok); yaklaşan dokunulmaz.
        $this->artisan('subscriptions:expire')->expectsOutputToContain('1 üyeliğin süresi doldu')->assertExitCode(0);
        $this->assertSame(['active', 'expired'], [$soon->fresh()->status, $late->fresh()->status]);
        $this->assertTrue(AuditLog::query()->where('action', 'subscription.expired')->where('entity_id', $late->id)->exists());
        $this->actingAs($finance)->get('/panel/uyelikler?sekme=expired')->assertOk()->assertSee('Süresi doldu');

        // Rapor üyelik sekmesi ve arama: finans şirket adıyla bulur.
        $this->actingAs($finance)->get('/panel/uyelikler?q=acme')->assertOk()->assertSee('Acme A.Ş.');
        $this->actingAs($finance)->get('/panel/uyelikler?q=yok')->assertOk()->assertSee('Bu sekmede üyelik yok');
    }
}
