<?php

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Space;
use App\Models\SpaceAssignment;
use App\Services\SpaceService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit P0-2 — Masa & ofis envanteri: lokasyon envanteri (geo.edit), doluluk, tahsis kuralları
 * (tek şirket / esnek kapasite / pasif alan / bloklu şirket / yabancı üye), sonlandırma,
 * zamanlayıcı, müşteri görünümü ve tenant sınırı, dashboard/rapor, izinler, audit.
 */
class SpaceTest extends TestCase
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
    public function envanter_doluluk_tahsis_kurallari_ve_musteri_gorunumu(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $acmeCo);
        $beta = $this->organization('Beta');
        $betaCo = $this->company($beta, 'Beta Ltd.');
        $betaOwner = $this->owner($beta, $betaCo);
        $ops = $this->staff('operations_admin');   // geo.edit + space.view/manage
        $finance = $this->staff('finance_admin');  // space.view, manage yok
        $kadikoy = Location::create(['name' => 'Kadıköy', 'slug' => 'kadikoy', 'city' => 'İstanbul', 'region' => 'Anadolu', 'is_active' => true, 'is_published' => true]);

        // Envanter (geo.edit): sabit masa, esnek alan (kapasite 2), ofis; aynı ad reddedilir; fiyat büyük birim → kuruş.
        $this->actingAs($finance)->get("/panel/geo/lokasyon/{$kadikoy->slug}/alanlar")->assertForbidden();
        $this->actingAs($ops)->get("/panel/geo/lokasyon/{$kadikoy->slug}/alanlar")->assertOk()->assertSee('Yeni alan');
        $this->actingAs($ops)->post("/panel/geo/lokasyon/{$kadikoy->slug}/alanlar", ['kind' => 'desk_fixed', 'name' => 'A-1', 'floor' => '2', 'monthly_price' => '3.500,00', 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($ops)->post("/panel/geo/lokasyon/{$kadikoy->slug}/alanlar", ['kind' => 'desk_flex', 'name' => 'Esnek', 'capacity' => 2, 'monthly_price' => '1500', 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($ops)->post("/panel/geo/lokasyon/{$kadikoy->slug}/alanlar", ['kind' => 'office', 'name' => 'Ofis 3', 'capacity' => 4, 'monthly_price' => '12000', 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($ops)->from("/panel/geo/lokasyon/{$kadikoy->slug}/alanlar")->post("/panel/geo/lokasyon/{$kadikoy->slug}/alanlar", ['kind' => 'desk_fixed', 'name' => 'A-1'])->assertSessionHasErrors('name');
        $this->actingAs($ops)->from("/panel/geo/lokasyon/{$kadikoy->slug}/alanlar")->post("/panel/geo/lokasyon/{$kadikoy->slug}/alanlar", ['kind' => 'garaj', 'name' => 'X'])->assertSessionHasErrors('kind');
        $desk = Space::query()->where('name', 'A-1')->firstOrFail();
        $flex = Space::query()->where('name', 'Esnek')->firstOrFail();
        $office = Space::query()->where('name', 'Ofis 3')->firstOrFail();
        $this->assertSame([350000, 1, 2], [$desk->monthly_price, $desk->slots(), $flex->slots()]);
        $this->assertTrue(AuditLog::query()->where('action', 'space.created')->exists());

        // Doluluk 0/4 yer (1 + 2 + 1); alanlar ekranı; finans görür ama tahsis edemez.
        $html = $this->actingAs($ops)->get('/panel/alanlar')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="k">Doluluk</span><span class="v">%0</span>', $html);
        $this->assertStringContainsString('0 / 4 yer tahsisli', $html);
        $this->assertStringContainsString('3.500,00 ₺', $html);
        $this->actingAs($finance)->get("/panel/alanlar/{$desk->id}")->assertOk()->assertSee('Tahsisler')->assertDontSee('Tahsis et');
        $this->actingAs($finance)->post("/panel/alanlar/{$desk->id}/tahsis", ['company_id' => $acmeCo->id, 'starts_on' => '2026-09-17'])->assertForbidden();

        // Tahsis: sabit masa Acme'ye (üye = owner); ikinci tahsis reddedilir; Beta'nın üyesi Acme masasına yazılamaz.
        $this->actingAs($ops)->get("/panel/alanlar/{$desk->id}?sirket={$acmeCo->id}")->assertOk()->assertSee($owner->name);
        $this->actingAs($ops)->post("/panel/alanlar/{$desk->id}/tahsis", ['company_id' => $acmeCo->id, 'user_id' => $owner->id, 'starts_on' => '2026-09-17', 'note' => 'Pencere kenarı'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($ops)->from("/panel/alanlar/{$desk->id}")->post("/panel/alanlar/{$desk->id}/tahsis", ['company_id' => $betaCo->id, 'starts_on' => '2026-09-17'])->assertSessionHasErrors('company_id');
        $this->actingAs($ops)->from("/panel/alanlar/{$flex->id}")->post("/panel/alanlar/{$flex->id}/tahsis", ['company_id' => $acmeCo->id, 'user_id' => $betaOwner->id, 'starts_on' => '2026-09-17'])->assertSessionHasErrors('user_id');
        $this->assertSame(1, SpaceAssignment::withoutTenantScope()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'space.assigned')->exists());

        // Esnek alan: kapasite 2 → iki şirket sığar, üçüncü dolu; bitiş başlangıçtan önce reddedilir.
        $this->actingAs($ops)->post("/panel/alanlar/{$flex->id}/tahsis", ['company_id' => $acmeCo->id, 'starts_on' => '2026-09-17', 'ends_on' => '2026-10-16'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($ops)->post("/panel/alanlar/{$flex->id}/tahsis", ['company_id' => $betaCo->id, 'starts_on' => '2026-09-17'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($ops)->from("/panel/alanlar/{$flex->id}")->post("/panel/alanlar/{$flex->id}/tahsis", ['company_id' => $acmeCo->id, 'starts_on' => '2026-09-17'])->assertSessionHasErrors('company_id');
        $this->actingAs($ops)->from("/panel/alanlar/{$office->id}")->post("/panel/alanlar/{$office->id}/tahsis", ['company_id' => $acmeCo->id, 'starts_on' => '2026-09-17', 'ends_on' => '2026-09-10'])->assertSessionHasErrors('company_id');

        // Doluluk 3/4 = %75; dashboard KPI; rapor doluluk sekmesi; alan pasife alınamaz (tahsisli).
        $html = $this->actingAs($ops)->get('/panel/alanlar')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="k">Doluluk</span><span class="v">%75</span>', $html);
        $this->assertStringContainsString('30 günde biten tahsis</span><span class="v">1</span>', $html);
        $dash = $this->actingAs($ops)->withContext($acme)->get('/panel/operasyon')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="k">Doluluk (masa/ofis)</span><span class="v">%75</span>', $dash);
        $this->actingAs($ops)->get('/panel/raporlar?sekme=doluluk')->assertOk()->assertSee('Masa &amp; ofis doluluğu', false)->assertSee('Sabit masa');
        $this->actingAs($ops)->from("/panel/geo/lokasyon/{$kadikoy->slug}/alanlar")->put("/panel/geo/lokasyon/{$kadikoy->slug}/alanlar/{$desk->id}", ['kind' => 'desk_fixed', 'name' => 'A-1', 'monthly_price' => '3500', 'is_active' => 0])->assertSessionHasErrors('name');
        $this->actingAs($ops)->from("/panel/geo/lokasyon/{$kadikoy->slug}/alanlar")->delete("/panel/geo/lokasyon/{$kadikoy->slug}/alanlar/{$desk->id}")->assertSessionHasErrors('space');

        // Müşteri: Acme sahibi kendi alanlarını görür (2 tahsis), Beta'nınkini görmez; Beta Acme sayfasına 404; müşteri tahsis edemez.
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}")->assertOk()->assertSee('Alanlar');
        $page = $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/alanlar")->assertOk()->getContent();
        $this->assertStringContainsString('A-1', $page);
        $this->assertStringContainsString('Esnek', $page);
        $this->assertSame(2, substr_count($page, 'pill g'));
        $this->actingAs($betaOwner)->withContext($beta)->get("/panel/sirketler/{$acmeCo->id}/alanlar")->assertNotFound();
        $this->actingAs($owner)->withContext($acme)->get('/panel/alanlar')->assertForbidden();

        // Sonlandır (space.manage, audit) → masa boş; zamanlayıcı bitişi geçen esnek tahsisi kapatır.
        $assignment = SpaceAssignment::withoutTenantScope()->where('space_id', $desk->id)->firstOrFail();
        $this->actingAs($finance)->post("/panel/alanlar/{$desk->id}/tahsis/{$assignment->id}/bitir")->assertForbidden();
        $this->actingAs($ops)->post("/panel/alanlar/{$desk->id}/tahsis/{$assignment->id}/bitir")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ended', $assignment->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'space.assignment_ended')->where('entity_id', $assignment->id)->exists());
        $this->assertFalse($desk->fresh()->isFull());
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->artisan('spaces:end-expired')->expectsOutputToContain('1 tahsis sona erdi')->assertExitCode(0);
        $this->assertSame(1, SpaceAssignment::withoutTenantScope()->where('status', 'active')->count()); // Beta'nın süresiz tahsisi
        $this->assertSame(25.0, app(SpaceService::class)->occupancy()['rate']);

        // Paket → alan türü bağı.
        $finance2 = $this->staff('finance_admin');
        $plan = app(SubscriptionService::class)->createPlan($finance2, ['name' => 'Sabit Masa Aylık', 'price' => 3500, 'period' => 'monthly', 'space_kind' => 'desk_fixed']);
        $this->assertSame('desk_fixed', $plan->space_kind);
        $this->actingAs($finance2)->get('/panel/paketler')->assertOk()->assertSee('Sabit masa');
    }
}
