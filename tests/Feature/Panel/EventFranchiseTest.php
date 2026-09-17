<?php

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\FranchiseApplication;
use App\Models\Location;
use App\Models\Website;
use App\Services\ContentCache;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 39d/e — Etkinlikler & topluluk, Franchise yönetimi: panel CRUD/yayın, vitrin liste/detay/
 * kayıt (KVKK, bot tuzağı, kontenjan, tekrar e-posta), önbellek geçersizleme, sitemap, tenant
 * sitesinde 404, franchise başvurusu → panel değerlendirme, rozet/dashboard, izinler, audit.
 */
class EventFranchiseTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
        Carbon::setTestNow('2026-09-17 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function etkinlik_panel_vitrin_kayit_onbellek_ve_izinler(): void
    {
        $ops = $this->staff('operations_admin');   // event.view + manage
        $finance = $this->staff('finance_admin');  // yok
        $site = Website::query()->default()->firstOrFail();
        $kadikoy = Location::create(['name' => 'Kadıköy', 'slug' => 'kadikoy', 'city' => 'İstanbul', 'region' => 'Anadolu', 'is_active' => true, 'is_published' => true]);

        $this->actingAs($finance)->get('/panel/etkinlikler')->assertForbidden();
        $this->actingAs($ops)->get('/panel/etkinlikler')->assertOk()->assertSee('Bu sekmede etkinlik yok')->assertSee('Yeni etkinlik');
        $this->get('/etkinlikler')->assertOk()->assertSee('planlanmış etkinlik yok');

        // Taslak: vitrinde yok; bitiş başlangıçtan önce reddedilir.
        $this->actingAs($ops)->from('/panel/etkinlikler/yeni')->post('/panel/etkinlikler', ['title' => 'Bozuk', 'starts_at' => '2026-09-25 18:00', 'ends_at' => '2026-09-25 17:00'])->assertSessionHasErrors('ends_at');
        $this->actingAs($ops)->post('/panel/etkinlikler', ['title' => 'Girişimci Kahvaltısı', 'summary' => 'Topluluk buluşması', 'description' => "## Program\n- Tanışma", 'location_id' => $kadikoy->id, 'starts_at' => '2026-09-25 09:00', 'ends_at' => '2026-09-25 11:00', 'capacity' => 2, 'price' => 0, 'is_published' => 0, 'registration_open' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $event = Event::query()->where('slug', 'girisimci-kahvaltisi')->firstOrFail();
        $this->get('/etkinlikler')->assertOk()->assertDontSee('Girişimci Kahvaltısı');
        $this->get('/etkinlik/girisimci-kahvaltisi')->assertNotFound();
        $this->actingAs($ops)->get('/panel/etkinlikler?sekme=draft')->assertOk()->assertSee('Girişimci Kahvaltısı')->assertSee('Taslak');

        // Yayınla → vitrin (önbellek düştü), detay (Markdown), sitemap.
        $this->get('/etkinlikler'); // önbelleği ısıt
        $before = app(ContentCache::class)->stats($site)['version'];
        $this->actingAs($ops)->put("/panel/etkinlikler/{$event->slug}", ['title' => 'Girişimci Kahvaltısı', 'summary' => 'Topluluk buluşması', 'description' => "## Program\n- Tanışma", 'location_id' => $kadikoy->id, 'starts_at' => '2026-09-25 09:00', 'ends_at' => '2026-09-25 11:00', 'capacity' => 2, 'price' => 0, 'is_published' => 1, 'registration_open' => 1])->assertRedirect();
        $this->assertGreaterThan($before, app(ContentCache::class)->stats($site)['version']);
        $this->get('/etkinlikler')->assertOk()->assertSee('1 yaklaşan etkinlik')->assertSee('Girişimci Kahvaltısı')->assertSee('Kadıköy')->assertSee('Ücretsiz');
        $this->get('/etkinlik/girisimci-kahvaltisi')->assertOk()->assertSee('<h2>Program</h2>', false)->assertSee('Kalan kontenjan: 2')->assertSee('Kayıt ol');
        $this->assertStringContainsString('/etkinlik/girisimci-kahvaltisi', $this->get('/sitemap.xml')->assertOk()->getContent());
        $this->assertStringContainsString('/franchise', $this->get('/sitemap.xml')->getContent());

        // Vitrin kaydı: KVKK zorunlu, bot tuzağı, kontenjan 2 → 3. kayıt reddedilir, aynı e-posta reddedilir.
        $form = ['name' => 'Ayşe Yılmaz', 'email' => 'Ayse@ornek.com', 'phone' => '0532', 'company_name' => 'Yılmaz Ltd.', 'kvkk' => '1'];
        $this->from('/etkinlik/girisimci-kahvaltisi')->post('/etkinlik/girisimci-kahvaltisi/kayit', ['kvkk' => null] + $form)->assertSessionHasErrors('kvkk');
        $this->from('/etkinlik/girisimci-kahvaltisi')->post('/etkinlik/girisimci-kahvaltisi/kayit', ['website' => 'bot'] + $form)->assertSessionHasErrors('website');
        $this->post('/etkinlik/girisimci-kahvaltisi/kayit', $form)->assertRedirect('/etkinlik/girisimci-kahvaltisi#kayit')->assertSessionHasNoErrors();
        $this->assertSame(['ayse@ornek.com', 'registered'], [EventRegistration::query()->firstOrFail()->email, EventRegistration::query()->firstOrFail()->status]);
        $this->assertNotNull(EventRegistration::query()->firstOrFail()->consented_at);
        $this->from('/etkinlik/girisimci-kahvaltisi')->post('/etkinlik/girisimci-kahvaltisi/kayit', $form)->assertSessionHasErrors('email'); // tekrar
        $this->post('/etkinlik/girisimci-kahvaltisi/kayit', ['email' => 'b@ornek.com'] + $form)->assertRedirect()->assertSessionHasNoErrors();
        $this->from('/etkinlik/girisimci-kahvaltisi')->post('/etkinlik/girisimci-kahvaltisi/kayit', ['email' => 'c@ornek.com'] + $form)->assertSessionHasErrors('email'); // dolu
        $this->get('/etkinlik/girisimci-kahvaltisi')->assertOk()->assertSee('Kontenjan doldu')->assertDontSee('Kayıt ol');

        // Panel: katılımcılar, durum değişimi (audit), kaydı olan etkinlik silinemez; dashboard KPI.
        $reg = EventRegistration::query()->where('email', 'ayse@ornek.com')->firstOrFail();
        $this->actingAs($ops)->get("/panel/etkinlikler/{$event->slug}")->assertOk()->assertSee('Ayşe Yılmaz')->assertSee('2 aktif kayıt / 2 kontenjan');
        $this->actingAs($ops)->post("/panel/etkinlikler/{$event->slug}/kayit/{$reg->id}", ['status' => 'cancelled'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $reg->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'event.registration_updated')->exists());
        $this->get('/etkinlik/girisimci-kahvaltisi')->assertOk()->assertSee('Kalan kontenjan: 1'); // iptal kontenjanı açtı
        $this->actingAs($ops)->from("/panel/etkinlikler/{$event->slug}")->delete("/panel/etkinlikler/{$event->slug}")->assertSessionHasErrors('event');
        $this->actingAs($finance)->post("/panel/etkinlikler/{$event->slug}/kayit/{$reg->id}", ['status' => 'attended'])->assertForbidden();
        $html = $this->actingAs($ops)->get('/panel/operasyon')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="k">Yaklaşan etkinlik</span><span class="v">1</span>', $html);
        $this->assertStringContainsString('<span class="t">Etkinlikler &amp; topluluk</span>', $html);

        // Tenant sitesi: etkinlik/franchise yok (host çözümlemesi; testin sonunda).
        Website::create(['organization_id' => $this->organization('Acme')->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
        $this->get('http://acme.example/etkinlikler')->assertNotFound();
        $this->get('http://acme.example/franchise')->assertNotFound();
    }

    #[Test]
    public function franchise_basvurusu_vitrinden_panele_rozet_ve_degerlendirme(): void
    {
        $ops = $this->staff('operations_admin');   // franchise.view + manage
        $finance = $this->staff('finance_admin');  // yok
        $acme = $this->organization('Acme');

        $this->get('/franchise')->assertOk()->assertSee('Başvuru formu')->assertSee('KVKK');
        $form = ['name' => 'Mehmet Kaya', 'email' => 'Mehmet@ornek.com', 'phone' => '0533', 'city' => 'İzmir', 'district' => 'Bornova', 'budget' => '2–3 milyon ₺', 'experience' => '5 yıl perakende', 'message' => 'Alsancak bölgesi', 'kvkk' => '1'];
        $this->from('/franchise')->post('/franchise', ['kvkk' => null] + $form)->assertSessionHasErrors('kvkk');
        $this->from('/franchise')->post('/franchise', ['website' => 'bot'] + $form)->assertSessionHasErrors('website');
        $this->from('/franchise')->post('/franchise', ['city' => ''] + $form)->assertSessionHasErrors('city');
        $this->post('/franchise', $form)->assertRedirect('/franchise')->assertSessionHasNoErrors();
        $this->get('/franchise')->assertOk()->assertSee('Başvurunuz alındı');
        $app = FranchiseApplication::query()->firstOrFail();
        $this->assertSame(['mehmet@ornek.com', 'new', 'İzmir'], [$app->email, $app->status, $app->city]);
        $this->assertNotNull($app->consented_at);

        // Panel: menü rozeti 1 (yeni), dashboard KPI, liste, detay; finans göremez.
        $html = $this->actingAs($ops)->withContext($acme)->get('/panel/operasyon')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<span class="t">Franchise yönetimi</span>\s*<span class="c a" aria-label="1 bekleyen">1</span>~', $html);
        $this->assertStringContainsString('<span class="k">Franchise başvurusu</span><span class="v">1</span>', $html);
        $this->actingAs($finance)->get('/panel/franchise')->assertForbidden();
        $this->actingAs($ops)->get('/panel/franchise')->assertOk()->assertSee('Mehmet Kaya')->assertSee('İzmir / Bornova')->assertSee('Yeni');
        $this->actingAs($ops)->get('/panel/franchise?q=kaya')->assertOk()->assertSee('Mehmet Kaya');
        $this->actingAs($ops)->get('/panel/franchise?status=approved')->assertOk()->assertDontSee('Mehmet Kaya');
        $this->actingAs($ops)->get("/panel/franchise/{$app->id}")->assertOk()->assertSee('5 yıl perakende')->assertSee('Değerlendirme');

        // Değerlendirme: durum + sorumlu + not (audit); rozet düşer; rapor talepler sekmesinde sayım.
        $this->actingAs($ops)->put("/panel/franchise/{$app->id}", ['status' => 'reviewing', 'assigned_to' => $ops->id, 'internal_note' => 'Arandı, lokasyon gezisi planlanacak.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['reviewing', $ops->id], [$app->fresh()->status, $app->fresh()->assigned_to]);
        $this->assertNotNull($app->fresh()->handled_at);
        $this->assertTrue(AuditLog::query()->where('action', 'franchise.updated')->where('entity_id', $app->id)->exists());
        $this->actingAs($ops)->from("/panel/franchise/{$app->id}")->put("/panel/franchise/{$app->id}", ['status' => 'bozuk'])->assertSessionHasErrors('status');
        $html = $this->actingAs($ops)->withContext($acme)->get('/panel/operasyon')->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('~Franchise yönetimi</span>\s*<span class="c a"~', $html);
        $this->actingAs($ops)->get('/panel/raporlar?sekme=talepler')->assertOk()->assertSee('Franchise başvuruları')->assertSee('Değerlendirmede');
    }
}
