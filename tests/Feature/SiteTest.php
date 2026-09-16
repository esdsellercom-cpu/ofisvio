<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Models\Lead;
use App\Models\Location;
use App\Support\ActivationJourney;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class); // talep formu çözüm seçenekleri veritabanından
    }

    // ---------------------------------------------------------------
    // Sayfa
    // ---------------------------------------------------------------

    #[Test]
    public function ana_sayfa_acilir(): void
    {
        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('Şirketinizin adresi', false)
            ->assertSee('Ofisvio');
    }

    #[Test]
    public function marka_adi_her_yerde_ofisviodur(): void
    {
        // Tasarım "Ofisviyo" diyordu; backend ve proje "Ofisvio". Yazım
        // farkının sayfaya sızmadığını doğrular.
        $html = $this->get(route('site.home'))->getContent();

        $this->assertStringNotContainsString('Ofisviyo', $html);
        $this->assertStringContainsString('Ofisvio', $html);
    }

    #[Test]
    public function yalnizca_yayindaki_lokasyonlar_gosterilir(): void
    {
        $hidden = Location::first();
        $hidden->update(['is_published' => false]);

        $response = $this->get(route('site.home'));

        $response->assertOk();
        $response->assertDontSee($hidden->name);
        $response->assertSee(Location::published()->first()->name);
    }

    #[Test]
    public function operasyonda_pasif_lokasyon_sitede_gorunmez(): void
    {
        // is_published ve is_active AYRI koşullardır; ikisi de gerekir.
        $loc = Location::first();
        $loc->update(['is_published' => true, 'is_active' => false]);

        $this->get(route('site.home'))->assertDontSee($loc->name);
    }

    #[Test]
    public function istatistikler_veritabanindan_hesaplanir(): void
    {
        // Tasarım mock'u "18 lokasyon · 6 şehir" diyordu; gerçek sayı seeder'dan
        // gelir. Sabit yazılmış rakam şube açıldıkça sessizce yalan olur.
        $count = Location::published()->count();

        $this->get(route('site.home'))->assertSeeInOrder([(string) $count, 'Lokasyon']);
    }

    #[Test]
    public function aktivasyon_akisi_state_machine_ile_tutarlidir(): void
    {
        // Akış adımları enum'dan türetilir. Yeni bir durum eklenip ne akışa
        // ne de istisna listesine konursa bu test kırılır — site sessizce
        // eskimez.
        $all = array_map(
            fn (CompanyStatus $s) => $s->value,
            CompanyStatus::cases()
        );

        $mapped = array_merge(
            ActivationJourney::coveredStatuses(),
            ActivationJourney::excludedStatuses()
        );

        $this->assertSame([], array_values(array_diff($all, $mapped)), 'Akışa eşlenmemiş durum var.');
        $this->assertSame(
            ActivationJourney::coveredStatuses(),
            array_unique(ActivationJourney::coveredStatuses()),
            'Aynı durum birden fazla adımda.'
        );
    }

    // ---------------------------------------------------------------
    // Teklif formu
    // ---------------------------------------------------------------

    private function validQuote(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'quote',
            'name' => 'Zeynep Aydın',
            'email' => 'zeynep@sirket.com',
            'phone' => '0532 000 00 00',
            'location_id' => Location::published()->first()->id,
            'solution' => 'Sanal Ofis',
            'team_size' => '2-5',
            'kvkk' => '1',
        ], $overrides);
    }

    #[Test]
    public function teklif_formu_kaydedilir(): void
    {
        $this->post(route('site.leads.store'), $this->validQuote())
            ->assertRedirect();

        $lead = Lead::firstOrFail();

        $this->assertSame('quote', $lead->kind);
        $this->assertSame('zeynep@sirket.com', $lead->email);
        $this->assertSame('Sanal Ofis', $lead->solution);
        $this->assertSame('new', $lead->status);
    }

    #[Test]
    public function kvkk_onayi_olmadan_kayit_olusmaz(): void
    {
        $this->post(route('site.leads.store'), $this->validQuote(['kvkk' => null]))
            ->assertSessionHasErrors('kvkk');

        $this->assertSame(0, Lead::count());
    }

    #[Test]
    public function kvkk_onayi_zaman_damgasi_ve_ip_ile_saklanir(): void
    {
        // KVKK açık rızası KANITLANABİLİR olmalı: boolean bir kutu yetmez.
        $this->post(route('site.leads.store'), $this->validQuote());

        $lead = Lead::firstOrFail();

        $this->assertNotNull($lead->consented_at);
        $this->assertNotNull($lead->consent_ip);
    }

    #[Test]
    public function eksik_ve_gecersiz_alanlar_reddedilir(): void
    {
        $this->post(route('site.leads.store'), $this->validQuote(['name' => '', 'email' => 'gecersiz']))
            ->assertSessionHasErrors(['name', 'email']);

        $this->assertSame(0, Lead::count());
    }

    #[Test]
    public function yayinda_olmayan_lokasyon_secilemez(): void
    {
        $hidden = Location::first();
        $hidden->update(['is_published' => false]);

        $this->post(route('site.leads.store'), $this->validQuote(['location_id' => $hidden->id]))
            ->assertSessionHasErrors('location_id');
    }

    #[Test]
    public function bot_tuzagi_dolu_gelen_istek_reddedilir(): void
    {
        $this->post(route('site.leads.store'), $this->validQuote(['website' => 'http://spam.example']))
            ->assertSessionHasErrors('website');

        $this->assertSame(0, Lead::count());
    }

    // ---------------------------------------------------------------
    // Ön rezervasyon
    // ---------------------------------------------------------------

    #[Test]
    public function on_rezervasyon_talebi_kaydedilir(): void
    {
        $this->post(route('site.leads.store'), [
            'kind' => 'booking',
            'name' => 'Mehmet Yıldız',
            'email' => 'mehmet@sirket.com',
            'location_id' => Location::published()->first()->id,
            'solution' => 'Toplantı Odası',
            'requested_date' => now()->addDay()->toDateString(),
            'requested_slot' => '10:00',
            'kvkk' => '1',
        ])->assertRedirect();

        $lead = Lead::firstOrFail();

        $this->assertSame('booking', $lead->kind);
        $this->assertSame('10:00', $lead->requested_slot);
    }

    #[Test]
    public function gecmis_tarihe_rezervasyon_alinmaz(): void
    {
        $this->post(route('site.leads.store'), [
            'kind' => 'booking',
            'name' => 'Mehmet Yıldız',
            'email' => 'mehmet@sirket.com',
            'requested_date' => now()->subDay()->toDateString(),
            'requested_slot' => '10:00',
            'kvkk' => '1',
        ])->assertSessionHasErrors('requested_date');
    }

    #[Test]
    public function lead_tenant_scope_tasimaz(): void
    {
        // Lead henüz hiçbir organizasyona ait değildir — "organizasyon
        // olmadan önceki" aşamadır. BelongsToTenant kullanılsaydı fail-closed
        // davranış gereği hiç kaydedilemezdi.
        $this->post(route('site.leads.store'), $this->validQuote());

        $this->assertSame(1, Lead::count());
    }
}
