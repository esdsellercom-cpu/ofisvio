<?php

namespace Tests\Feature\Panel;

use App\Models\Lead;
use App\Models\Location;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit — CRM v1: vitrin formundan gelen talep panelde görünür, süzülür,
 * atanır, durumu değişir; izinler lead.view / lead.assign; veriler DB'den.
 */
class LeadAdminTest extends TestCase
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
    public function vitrin_talebi_panelde_gorunur_atanir_ve_durumu_degisir(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin');
        $finance = $this->staff('finance_admin'); // lead.* yok

        // Vitrin → DB (gerçek form akışı).
        $this->post('/talep', ['kind' => 'quote', 'name' => 'Ayşe Yılmaz', 'email' => 'ayse@ornek.com', 'phone' => '0532 000 00 00', 'solution' => 'Sanal Ofis', 'team_size' => '2-5', 'kvkk' => '1'])->assertRedirect();
        $lead = Lead::where('email', 'ayse@ornek.com')->firstOrFail();
        $this->assertSame('new', $lead->status);

        // Liste + süzgeç + arama.
        $this->actingAs($admin)->get('/panel/talepler')->assertOk()->assertSee('Ayşe Yılmaz')->assertSee('Yeni');
        $this->actingAs($admin)->get('/panel/talepler?q=ayse')->assertOk()->assertSee('Ayşe Yılmaz');
        $this->actingAs($admin)->get('/panel/talepler?q=yok')->assertOk()->assertDontSee('Ayşe Yılmaz');
        $this->actingAs($admin)->get('/panel/talepler?kind=booking')->assertOk()->assertDontSee('Ayşe Yılmaz');
        $this->actingAs($admin)->get('/panel/talepler?status=bozuk')->assertSessionHasErrors('status');

        // Atama + durum (lead.assign); DB'ye yazılır, refresh sonrası gelir.
        $this->actingAs($admin)->put("/panel/talepler/{$lead->id}", ['status' => 'contacted', 'assigned_to' => $ops->id, 'internal_note' => 'Arandı, teklif gönderilecek.'])->assertRedirect("/panel/talepler/{$lead->id}");
        $lead->refresh();
        $this->assertSame('contacted', $lead->status);
        $this->assertSame($ops->id, $lead->assigned_to);
        $this->assertNotNull($lead->handled_at);
        $this->actingAs($admin)->get("/panel/talepler/{$lead->id}")->assertOk()->assertSee('Arandı, teklif gönderilecek.')->assertSee($ops->name);

        // Personel olmayan kişiye atanamaz; geçersiz durum reddedilir.
        $owner = $this->owner($this->organization('Acme'));
        $this->actingAs($admin)->from("/panel/talepler/{$lead->id}")->put("/panel/talepler/{$lead->id}", ['status' => 'won', 'assigned_to' => $owner->id])->assertSessionHasErrors('assigned_to');
        $this->actingAs($admin)->from("/panel/talepler/{$lead->id}")->put("/panel/talepler/{$lead->id}", ['status' => 'kapandi'])->assertSessionHasErrors('status');

        // İzinler: finance_admin göremez; müşteri sahibi giremez.
        $this->actingAs($finance)->get('/panel/talepler')->assertForbidden();
        $this->actingAs($owner)->get('/panel/talepler')->assertForbidden();
        $this->actingAs($owner)->put("/panel/talepler/{$lead->id}", ['status' => 'won'])->assertForbidden();

        // Ön rezervasyon türü lokasyonla listelenir.
        $location = Location::published()->firstOrFail();
        $this->post('/talep', ['kind' => 'booking', 'name' => 'Mehmet Kaya', 'email' => 'mehmet@ornek.com', 'location_id' => $location->id, 'requested_date' => now()->addDay()->toDateString(), 'requested_slot' => '10:00', 'kvkk' => '1'])->assertRedirect();
        $this->actingAs($admin)->get('/panel/talepler?kind=booking')->assertOk()->assertSee('Mehmet Kaya')->assertSee($location->name);
    }
}
