<?php

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Service;
use App\Models\SiteBlock;
use App\Models\Website;
use App\Services\ContentCache;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 4 — Hizmet modülü: hizmetler gerçek varlık, lokasyon ↔ hizmet ilişkisel. Admin'den
 * hizmet ekle → vitrin (çözüm kartı, süzgeç, teklif formu seçeneği, hizmet sayfası, JSON-LD,
 * sitemap); lokasyona bağla → kart etiketi + hizmet sayfasında lokasyon; kaldır → vitrinden
 * düşer; önbellek geçersizleme; tenant izolasyonu; lokasyon ekranı hizmet oluşturmaz.
 */
class ServiceTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(LocationSeeder::class); // ServiceSeeder'ı çağırır ve ilişkileri kurar
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
        $this->site = Website::query()->default()->firstOrFail();
    }

    #[Test]
    public function seed_iliskisel_ve_vitrin_hizmetlerden_beslenir(): void
    {
        // Seed: hizmet kataloğu + lokasyon ilişkileri; etiket dizisi ve çözüm bloğu yok.
        $this->assertSame(6, Service::count());
        $this->assertSame(0, SiteBlock::query()->where('key', 'solutions')->count());
        $this->assertFalse(Schema::hasColumn('locations', 'tags'));
        $levent = Location::query()->where('slug', 'levent-199')->firstOrFail();
        $this->assertSame(['Hazır Ofis', 'Coworking', 'Toplantı Odası'], $levent->serviceNames());

        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('href="/cozum/sanal-ofis"', $home);            // çözüm kartı → hizmet sayfası
        $this->assertStringContainsString('amiral ürün', $home);
        $this->assertStringContainsString('<option value="Sanal Ofis">Sanal Ofis</option>', $home); // hero süzgeci + teklif formu
        $this->assertStringContainsString('data-tags="Hazır Ofis|Coworking|Toplantı Odası"', $home); // lokasyon kartı etiketleri
        $this->assertStringContainsString('"@type":"Service","name":"Toplantı Odası","url":"'.config('app.url').'/cozum/toplanti-odasi"', $home);
        $this->assertStringContainsString('/cozum/hazir-ofis', $this->get('/sitemap.xml')->assertOk()->getContent());

        // Hizmet sayfası: hizmet + lokasyon ilişkisi, rezervasyon türü (odalar).
        $page = $this->get('/cozum/toplanti-odasi')->assertOk()->getContent();
        $this->assertStringContainsString('Levent 199', $page);
        $this->assertStringContainsString('Uygun saatleri gör', $page);
        $this->assertStringNotContainsString('Nişantaşı Teşvikiye', $page); // bu hizmeti sunmuyor
        $this->get('/cozumler')->assertOk()->assertSee('6 hizmet');
        $this->get('/cozum/olmayan')->assertNotFound();
        // Lokasyon sayfası: JSON-LD makesOffer hizmet sayfasına bağlanır.
        $this->assertStringContainsString('"itemOffered":{"@type":"Service","name":"Coworking","url":"'.config('app.url').'/cozum/coworking"}', $this->get('/lokasyon/levent-199')->assertOk()->getContent());
    }

    #[Test]
    public function admin_hizmet_ekler_lokasyona_baglar_kaldirir_vitrin_ve_onbellek_izler(): void
    {
        $ops = $this->staff('operations_admin'); // service.manage + geo.edit
        $finance = $this->staff('finance_admin');
        $levent = Location::query()->where('slug', 'levent-199')->firstOrFail();

        $this->actingAs($finance)->get('/panel/hizmetler')->assertForbidden();
        $this->actingAs($ops)->get('/panel/hizmetler')->assertOk()->assertSee('Sanal Ofis')->assertSee('Yeni hizmet');

        // 1) Admin'den hizmet ekle → vitrin çözüm kartı, süzgeç seçeneği, teklif formu kabulü, sitemap (önbellek düştü).
        $this->get('http://localhost/'); // önbelleği ısıt
        $this->actingAs($ops)->post('/panel/hizmetler', ['name' => 'Yerleşik Depo', 'summary' => 'Kilitli depo alanı', 'price_text' => '₺1.500/ay', 'is_active' => 1, 'sort_order' => 9])
            ->assertRedirect('/panel/hizmetler')->assertSessionHasNoErrors();
        $depo = Service::query()->where('slug', 'yerlesik-depo')->firstOrFail();
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('Yerleşik Depo', $home);
        $this->assertStringContainsString('href="/cozum/yerlesik-depo"', $home);
        $this->assertStringContainsString('<option value="Yerleşik Depo">', $home);
        $this->post('/talep', ['kind' => 'quote', 'name' => 'Ayşe', 'email' => 'ayse@ornek.com', 'solution' => 'Yerleşik Depo', 'kvkk' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringContainsString('/cozum/yerlesik-depo', $this->get('/sitemap.xml')->getContent());
        $this->assertTrue(AuditLog::query()->where('action', 'service.created')->exists());

        // 2) Lokasyon künyesinden seç (var olan hizmet); lokasyon ekranı hizmet OLUŞTURMAZ (bilinmeyen id yok sayılır).
        $ids = $levent->services()->pluck('services.id')->push($depo->id)->all();
        $this->actingAs($ops)->put("/panel/geo/lokasyon/{$levent->slug}/kunye", ['name' => $levent->name, 'city' => $levent->city, 'region' => $levent->region, 'services' => array_merge($ids, [99999]), 'is_active' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['Hazır Ofis', 'Coworking', 'Toplantı Odası', 'Yerleşik Depo'], $levent->fresh()->serviceNames());
        $this->assertSame(7, Service::count()); // 99999 için hizmet açılmadı
        $this->assertStringContainsString('data-tags="Hazır Ofis|Coworking|Toplantı Odası|Yerleşik Depo"', $this->get('http://localhost/')->getContent());
        $this->assertStringContainsString('Levent 199', $this->get('/cozum/yerlesik-depo')->assertOk()->getContent());
        $this->assertTrue(AuditLog::query()->where('action', 'location.services_changed')->exists());
        $this->actingAs($ops)->get("/panel/geo/lokasyon/{$levent->slug}")->assertOk()->assertSee('Sunulan hizmetler')->assertSee('Hizmetler</a> modülünden', false);

        // 3) Bağlıyken silinemez; lokasyondan kaldır → kart etiketinden düşer; sonra sil → vitrinden tamamen kalkar.
        $this->actingAs($ops)->from('/panel/hizmetler')->delete("/panel/hizmetler/{$depo->slug}")->assertSessionHasErrors('service');
        $this->assertNotNull($depo->fresh());
        $this->actingAs($ops)->put("/panel/geo/lokasyon/{$levent->slug}/kunye", ['name' => $levent->name, 'services' => $levent->services()->where('services.id', '!=', $depo->id)->pluck('services.id')->all(), 'is_active' => 1])->assertRedirect();
        $this->assertStringNotContainsString('|Yerleşik Depo"', $this->get('http://localhost/')->getContent());
        $this->assertStringContainsString('yayında lokasyon yok', $this->get('/cozum/yerlesik-depo')->assertOk()->getContent());
        $this->actingAs($ops)->delete("/panel/hizmetler/{$depo->slug}")->assertRedirect('/panel/hizmetler')->assertSessionHasNoErrors();
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Yerleşik Depo', $home);
        $this->get('/cozum/yerlesik-depo')->assertNotFound();
        $this->post('/talep', ['kind' => 'quote', 'name' => 'Ayşe', 'email' => 'ayse2@ornek.com', 'solution' => 'Yerleşik Depo', 'kvkk' => '1'])->assertSessionHasErrors('solution');

        // 4) Pasif hizmet: vitrin/süzgeç/sitemap'ten düşer, panelde kalır; önbellek geçersizlemesi (sürüm arttı).
        $sanal = Service::query()->where('slug', 'sanal-ofis')->firstOrFail();
        $before = app(ContentCache::class)->stats($this->site)['version'];
        $this->actingAs($ops)->put("/panel/hizmetler/{$sanal->slug}", ['name' => 'Sanal Ofis', 'summary' => $sanal->summary, 'price_text' => $sanal->price_text, 'is_active' => 0, 'is_flagship' => 1, 'sort_order' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertGreaterThan($before, app(ContentCache::class)->stats($this->site)['version']);
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-solution-pick="Sanal Ofis"', $home); // kart yok (footer sütunu ayrı blok verisi)
        $this->assertStringNotContainsString('<option value="Sanal Ofis">', $home);
        $this->assertStringNotContainsString('/cozum/sanal-ofis', $this->get('/sitemap.xml')->getContent());
        $this->get('/cozum/sanal-ofis')->assertNotFound();
        $this->actingAs($ops)->get('/panel/hizmetler')->assertOk()->assertSee('Pasif');
        // Pasif hizmet lokasyon etiketinde de görünmez ama ilişki korunur.
        $maslak = Location::query()->where('slug', 'maslak-vadi')->firstOrFail();
        $this->assertNotContains('Sanal Ofis', $maslak->serviceNames());
        $this->assertSame(1, $maslak->services()->where('services.id', $sanal->id)->count());
    }

    #[Test]
    public function tenant_izolasyonu_musteri_hizmet_yonetemez_ve_musteri_sitesi_hizmet_basmaz(): void
    {
        $acme = $this->organization('Acme');
        $owner = $this->owner($acme, $this->company($acme, 'Acme A.Ş.'));
        Website::create(['organization_id' => $acme->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);

        $this->actingAs($owner)->withContext($acme)->get('/panel/hizmetler')->assertForbidden();
        $this->actingAs($owner)->withContext($acme)->post('/panel/hizmetler', ['name' => 'Sızma', 'is_active' => 1])->assertForbidden();
        $this->actingAs($owner)->withContext($acme)->get('/panel/geo/lokasyon/levent-199')->assertForbidden();
        $this->assertSame(6, Service::count());

        // Müşteri sitesi operatörün hizmet kataloğunu basmaz; hizmet rotaları orada 404.
        $this->get('http://acme.example/')->assertOk()->assertDontSee('Sanal Ofis')->assertDontSee('/cozum/');
        $this->get('http://acme.example/cozumler')->assertNotFound();
        $this->get('http://acme.example/cozum/sanal-ofis')->assertNotFound();
        $this->assertStringNotContainsString('/cozum/', $this->get('http://acme.example/sitemap.xml')->assertOk()->getContent());
    }
}
