<?php

namespace Tests\Feature\Panel;

use App\Models\Location;
use App\Models\Website;
use App\Services\GeoService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 16-17 — GEO / Entity: lokasyon sayfaları, LocalBusiness + Breadcrumb
 * şeması, Organization sameAs, denetim, izinler.
 */
class GeoEngineTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Website $site;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->seed(LocationSeeder::class);
        $this->site = Website::query()->default()->firstOrFail();
        $this->location = Location::published()->firstOrFail();
    }

    #[Test]
    public function lokasyon_sayfasi_localbusiness_ve_breadcrumb_semasi_tasir(): void
    {
        $this->get('/lokasyonlar')->assertOk()->assertSee($this->location->name)->assertSee('14 şube');

        $html = $this->get($this->location->path())->assertOk()->getContent();

        $this->assertStringContainsString($this->location->name, $html);
        $this->assertStringContainsString('"@type":"LocalBusiness"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"@type":"Organization"', $html);
        $this->assertStringContainsString('"addressCountry":"TR"', $html);
        // Koordinat/telefon YOK -> şemada uydurma alan yok.
        $this->assertStringNotContainsString('GeoCoordinates', $html);
        $this->assertStringNotContainsString('"telephone"', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.config('app.url').$this->location->path().'">', $html);

        // Sitemap'te lokasyonlar var.
        $this->get('/sitemap.xml')->assertSee('<loc>'.config('app.url').'/lokasyonlar</loc>', false)->assertSee('<loc>'.config('app.url').$this->location->path().'</loc>', false);

        // Yayında olmayan lokasyon 404.
        $hidden = Location::create(['name' => 'Gizli Şube', 'slug' => 'gizli-sube', 'is_published' => false]);
        $this->get('/lokasyon/gizli-sube')->assertNotFound();
        $this->get('/lokasyonlar')->assertDontSee('Gizli Şube');
    }

    #[Test]
    public function musteri_sitesinde_lokasyon_sayfalari_yoktur(): void
    {
        Website::create(['organization_id' => $this->organization('Acme')->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);

        $this->get('http://acme.example/lokasyonlar')->assertNotFound();
        $this->get('http://acme.example'.$this->location->path())->assertNotFound();
        $this->get('http://acme.example/sitemap.xml')->assertDontSee('/lokasyon');
    }

    #[Test]
    public function varlik_alanlari_semaya_girer_ve_denetim_temizlenir(): void
    {
        $admin = $this->staff('system_admin');

        $before = collect(app(GeoService::class)->audit())->firstWhere('location.id', $this->location->id);
        $this->assertNotNull($before);
        $this->assertContains('Koordinat yok — haritada ve yerel aramada çıkmaz.', $before['issues']);

        $this->actingAs($admin)->get("/panel/geo/lokasyon/{$this->location->slug}")->assertOk()->assertSee('Enlem');

        $this->actingAs($admin)->put("/panel/geo/lokasyon/{$this->location->slug}", [
            'latitude' => '41.0621', 'longitude' => '29.0073', 'district' => 'Beşiktaş', 'postal_code' => '34330',
            'phone' => '0850 840 00 01', 'opening_hours' => "Mo-Fr 08:30-19:00\nSa 09:00-14:00",
            'geo_description' => str_repeat('Şube açıklaması. ', 20), 'geo_meta_description' => 'Kısa meta.',
        ])->assertRedirect('/panel/geo');

        $html = $this->get('http://localhost'.$this->location->path())->assertOk()->getContent();
        $this->assertStringContainsString('"@type":"GeoCoordinates"', $html);
        $this->assertStringContainsString('"telephone":"0850 840 00 01"', $html);
        $this->assertStringContainsString('"openingHours":["Mo-Fr 08:30-19:00","Sa 09:00-14:00"]', $html);
        $this->assertStringContainsString('"addressLocality":"Beşiktaş"', $html);
        $this->assertStringContainsString('Haritada aç', $html);

        $after = collect(app(GeoService::class)->audit())->firstWhere('location.id', $this->location->id);
        $this->assertNull($after, 'Tüm alanlar dolunca bulgu kalmamalı.');

        // Yarım koordinat reddedilir.
        $this->actingAs($admin)->from("/panel/geo/lokasyon/{$this->location->slug}")
            ->put("/panel/geo/lokasyon/{$this->location->slug}", ['latitude' => '41.0'])
            ->assertSessionHasErrors('longitude');
    }

    #[Test]
    public function organizasyon_varligi_jit_ister_ve_ana_sayfa_semasina_girer(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // geo.view/edit/audit var, settings yok

        $this->actingAs($ops)->get('/panel/geo')->assertOk()->assertSee('Düzenle')->assertDontSee('JIT erişimi iste');
        $this->actingAs($ops)->put("/panel/geo/{$this->site->id}/varlik", ['legal_name' => 'X'])->assertForbidden();

        $this->actingAs($admin)->put("/panel/geo/{$this->site->id}/varlik", ['legal_name' => 'X'])->assertForbidden();
        $this->actingAs($admin)->post("/panel/geo/{$this->site->id}/jit", ['reason' => 'sosyal profiller bilgi grafiğine eklenecek', 'ttl_minutes' => 30])->assertRedirect('/panel/geo');

        // Geçersiz sameAs reddedilir (http:// ya da URL olmayan).
        $this->actingAs($admin)->from('/panel/geo')
            ->put("/panel/geo/{$this->site->id}/varlik", ['legal_name' => 'Ofisvio A.Ş.', 'same_as' => "http://insecure.example\nbozuk"])
            ->assertSessionHasErrors('same_as');

        $this->actingAs($admin)->put("/panel/geo/{$this->site->id}/varlik", [
            'legal_name' => 'Ofisvio Gayrimenkul ve İşletme A.Ş.',
            'same_as' => "https://www.linkedin.com/company/ofisvio\nhttps://www.instagram.com/ofisvio",
        ])->assertRedirect('/panel/geo');

        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('"legalName":"Ofisvio Gayrimenkul ve İşletme A.Ş."', $home);
        $this->assertStringContainsString('"sameAs":["https://www.linkedin.com/company/ofisvio","https://www.instagram.com/ofisvio"]', $home);

        // Müşteri kullanıcısı GEO paneline giremez (geo.view company kapsamlı; global değil).
        $owner = $this->owner($this->organization('Beta'));
        $this->actingAs($owner)->get('/panel/geo')->assertForbidden();
    }
}
