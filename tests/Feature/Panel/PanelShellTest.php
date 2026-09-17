<?php

namespace Tests\Feature\Panel;

use App\Models\Booking;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Room;
use Database\Seeders\ServiceSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 38 — panel kabuğu ("Kolektif Panel" kalıbı): gruplu/numaralı/rozetli menü izne göre,
 * 2FA kapısı, dashboard KPI'ları gerçek servis toplamları, üst çubuk araması izinli kümeler,
 * tema tercihi veritabanında. Sahte sayaç yok: rozet ve KPI değerleri oluşturulan kayıtlardan.
 */
class PanelShellTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
        $this->seed(ServiceSeeder::class); // teklif formu çözüm seçeneği (aktif hizmet)
        Carbon::setTestNow('2026-09-17 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function menu_izne_gore_numarali_ve_rozetli_dashboard_gercek_toplamlari_basar(): void
    {
        $acme = $this->organization('Acme');
        $ops = $this->staff('operations_admin');   // booking.view, lead.view, geo.view — kyc.view_status yok
        $finance = $this->staff('finance_admin');  // operasyon izinleri yok

        $kadikoy = Location::create(['name' => 'Kadıköy', 'slug' => 'kadikoy', 'city' => 'İstanbul', 'region' => 'Anadolu', 'is_active' => true, 'is_published' => true]);
        $room = Room::create(['location_id' => $kadikoy->id, 'name' => 'Toplantı 1', 'kind' => 'meeting', 'capacity' => 6, 'hourly_rate' => 400, 'open_from' => '09:00', 'open_until' => '18:00', 'slot_minutes' => 60, 'max_hours' => 4]);

        // Gerçek kayıtlar: vitrin rezervasyon talebi (onay bekler, bugün) + vitrin teklif talebi.
        $this->post('/rezervasyon', ['room_id' => $room->id, 'date' => '2026-09-17', 'start' => '14:00', 'hours' => 1, 'participants' => 4, 'name' => 'Ahmet Yılmaz', 'email' => 'ahmet@ornek.com', 'phone' => '+905321112233', 'company_name' => 'Yılmaz Ltd.', 'kvkk' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/talep', ['kind' => 'quote', 'name' => 'Ayşe Yılmaz', 'email' => 'ayse@ornek.com', 'solution' => 'Sanal Ofis', 'kvkk' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $booking = Booking::withoutTenantScope()->firstOrFail();
        $this->assertSame(1, Lead::query()->where('status', 'new')->count());

        // Operasyon: menü grupları + sıralı numaralar + rozetler (bekleyen rezervasyon 1, yeni talep 1).
        $html = $this->actingAs($ops)->withContext($acme)->get('/panel')->assertOk()->getContent();
        $this->assertStringContainsString('class="ap-nav__h">Genel bakış<', $html);
        $this->assertStringContainsString('class="ap-nav__h">Operasyon<', $html);
        $this->assertStringContainsString('class="ap-nav__h">Sistem<', $html);
        $this->assertMatchesRegularExpression('~<span class="n" aria-hidden="true">1</span>\s*<span class="t">Dashboard</span>~', $html);
        $this->assertMatchesRegularExpression('~<span class="t">Rezervasyonlar</span>\s*<span class="c w" aria-label="1 bekleyen">1</span>~', $html);
        $this->assertMatchesRegularExpression('~<span class="t">Talepler &amp; CRM</span>\s*<span class="c a" aria-label="1 bekleyen">1</span>~', $html);
        $this->assertStringNotContainsString('KYC kuyruğu', $html); // izin yok
        $this->assertStringNotContainsString('Kullanıcılar &amp; roller', $html);
        preg_match_all('~<span class="n" aria-hidden="true">(\d+)</span>~', $html, $m);
        $this->assertSame(range(1, count($m[1])), array_map('intval', $m[1]), 'Menü numaraları boşluksuz ve sıralı olmalı.');

        // Dashboard KPI'ları gerçek: bugün 1, onay bekleyen 1 (alert), yeni talep 1; bugünkü tablo + bekleyenler + talepler.
        $this->assertStringContainsString('<span class="k">Bugünkü rezervasyon</span><span class="v">1</span>', $html);
        $this->assertStringContainsString('class="kpi alert"><span class="k">Onay bekleyen</span><span class="v">1</span>', $html);
        $this->assertStringContainsString('<span class="k">Yeni talep</span><span class="v">1</span>', $html);
        $this->assertStringContainsString('<span class="k">Yayında lokasyon</span><span class="v">1</span>', $html);
        $this->assertStringContainsString('14:00–15:00', $html);
        $this->assertStringContainsString($booking->reference, $html);
        $this->assertStringContainsString('Ahmet Yılmaz', $html);
        $this->assertStringContainsString('Ayşe Yılmaz', $html);
        $this->assertStringNotContainsString('Bekleyen KYC', $html);
        $this->assertStringContainsString('Kadıköy', $html); // lokasyon performansı (30g)

        // Finans: operasyon rozetleri/kartları yok, menüde rezervasyon yok; dashboard yine açılır.
        $html = $this->actingAs($finance)->withContext($acme)->get('/panel')->assertOk()->getContent();
        $this->assertStringNotContainsString('<span class="t">Rezervasyonlar</span>', $html);
        $this->assertStringNotContainsString('Onay bekleyen', $html);
        $this->assertStringNotContainsString('Ahmet Yılmaz', $html);
        $this->assertStringContainsString('<span class="t">Şirketler</span>', $html);

        // Müşteri (owner): şirket sayaçları; operasyon özeti ve personel menüleri yok.
        $owner = $this->owner($acme, $this->company($acme, 'Acme A.Ş.'));
        $html = $this->actingAs($owner)->withContext($acme)->get('/panel')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="k">Şirket</span><span class="v">1</span>', $html);
        $this->assertStringNotContainsString('Bugünkü rezervasyon', $html);
        $this->assertStringNotContainsString('class="ap-nav__h">Sistem<', $html);
        $this->assertStringContainsString('Müşteri</small>', $html);
    }

    #[Test]
    public function iki_adimli_dogrulamasiz_personel_yalniz_kurulum_menusu_gorur(): void
    {
        $html = $this->actingAs($this->staffWithoutTwoFactor())->get('/panel/hesap')->assertOk()->getContent();
        $this->assertStringContainsString('class="ap-nav__h">Kurulum<', $html);
        $this->assertStringContainsString('İki adımlı doğrulamayı kur', $html);
        $this->assertStringNotContainsString('class="ap-nav__h">Sistem<', $html);
        $this->assertStringNotContainsString('class="ap-search"', $html); // arama kutusu da kapalı
    }

    #[Test]
    public function ust_cubuk_aramasi_yalniz_izinli_kumeleri_arar(): void
    {
        $ops = $this->staff('operations_admin');
        $finance = $this->staff('finance_admin');
        $kadikoy = Location::create(['name' => 'Kadıköy', 'slug' => 'kadikoy', 'city' => 'İstanbul', 'region' => 'Anadolu', 'is_active' => true, 'is_published' => true]);
        $room = Room::create(['location_id' => $kadikoy->id, 'name' => 'Toplantı 1', 'kind' => 'meeting', 'capacity' => 6, 'hourly_rate' => 400, 'open_from' => '09:00', 'open_until' => '18:00', 'slot_minutes' => 60, 'max_hours' => 4]);
        $this->post('/rezervasyon', ['room_id' => $room->id, 'date' => '2026-09-18', 'start' => '10:00', 'hours' => 1, 'participants' => 2, 'name' => 'Ahmet Yılmaz', 'email' => 'ahmet@ornek.com', 'phone' => '+905321112233', 'kvkk' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $booking = Booking::withoutTenantScope()->firstOrFail();

        $this->actingAs($ops)->get('/panel/ara?q='.$booking->reference)->assertOk()->assertSee($booking->reference)->assertSee('Ahmet Yılmaz')->assertSee('Rezervasyonlar');
        $this->actingAs($ops)->get('/panel/ara?q=ahmet')->assertOk()->assertSee('Ahmet Yılmaz');
        $this->actingAs($ops)->get('/panel/ara?q=yok')->assertOk()->assertSee('için sonuç yok')->assertSee('rezervasyon, talep');
        $this->actingAs($ops)->get('/panel/ara?q=a')->assertOk()->assertSee('En az 2 karakter');
        // Finans: booking.view yok → rezervasyon kümesi hiç aranmaz.
        $this->actingAs($finance)->get('/panel/ara?q=ahmet')->assertOk()->assertDontSee('Ahmet Yılmaz')->assertDontSee('rezervasyon');
    }

    #[Test]
    public function tema_tercihi_veritabaninda_saklanir(): void
    {
        $ops = $this->staff('operations_admin');

        $this->actingAs($ops)->get('/panel/hesap')->assertOk()->assertSee('<html lang="tr" class="panel" >', false);
        $this->actingAs($ops)->from('/panel/hesap')->post('/panel/hesap/tema', ['theme' => 'dark'])->assertRedirect('/panel/hesap');
        $this->assertSame('dark', $ops->fresh()->ui_theme);
        $this->actingAs($ops)->get('/panel/hesap')->assertOk()->assertSee('data-theme="dark"', false);
        $this->actingAs($ops)->from('/panel/hesap')->post('/panel/hesap/tema', ['theme' => 'mavi'])->assertSessionHasErrors('theme');
        $this->actingAs($ops)->post('/panel/hesap/tema', ['theme' => 'system'])->assertRedirect();
        $this->assertNull($ops->fresh()->ui_theme);
    }
}
