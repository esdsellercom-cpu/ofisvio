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
        $this->assertMatchesRegularExpression('~<span class="t">CRM &amp; pazarlama</span>\s*<span class="c a" aria-label="1 bekleyen">1</span>~', $html);
        $this->assertStringNotContainsString('KYC kuyruğu', $html); // izin yok
        $this->assertStringNotContainsString('Roller, yetkiler &amp; güvenlik', $html); // user.manage yok
        $this->assertStringContainsString('<span class="t">Masalar, ofisler &amp; odalar</span>', $html);
        $this->assertStringContainsString('<span class="t">Raporlar &amp; analitik</span>', $html); // analytics.view
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
    public function faz39_sayfalari_izne_gore_acilir_ve_gercek_toplam_basar(): void
    {
        $acme = $this->organization('Acme');
        $ops = $this->staff('operations_admin');
        $finance = $this->staff('finance_admin');
        $admin = $this->staff('system_admin');
        $kadikoy = Location::create(['name' => 'Kadıköy', 'slug' => 'kadikoy', 'city' => 'İstanbul', 'region' => 'Anadolu', 'is_active' => true, 'is_published' => true]);
        Room::create(['location_id' => $kadikoy->id, 'name' => 'Toplantı 1', 'kind' => 'meeting', 'capacity' => 6, 'hourly_rate' => 400, 'open_from' => '09:00', 'open_until' => '18:00', 'slot_minutes' => 60, 'max_hours' => 4]);
        Room::create(['location_id' => $kadikoy->id, 'name' => 'Odak 1', 'kind' => 'focus', 'capacity' => 1, 'hourly_rate' => 150, 'open_from' => '09:00', 'open_until' => '18:00', 'slot_minutes' => 60, 'max_hours' => 8, 'is_active' => false]);
        $owner = $this->owner($acme, $this->company($acme, 'Acme A.Ş.'));

        // Alanlar (geo.view|booking.view): lokasyon başlığı, tür, aktif/pasif; finans göremez.
        $this->actingAs($ops)->get('/panel/alanlar')->assertOk()->assertSee('Kadıköy')->assertSee('Toplantı odası')->assertSee('Odaklanma odası')
            ->assertSee('<span class="k">Rezervasyona açık</span><span class="v">1</span>', false)->assertSee('Odaları yönet');
        $this->actingAs($finance)->get('/panel/alanlar')->assertForbidden();

        // Raporlar (analytics.view): sekmeler; KYC adedi yalnız kyc.view_status taşıyana.
        $this->actingAs($ops)->get('/panel/raporlar')->assertOk()->assertSee('Onaylı tutar (30g)')->assertSee('Lokasyona göre rezervasyon');
        $this->actingAs($ops)->get('/panel/raporlar?sekme=doluluk')->assertOk()->assertSee('Alan türleri')->assertSee('<span class="k">Alan</span><span class="v">1</span>', false);
        $this->actingAs($ops)->get('/panel/raporlar?sekme=uyelik')->assertOk()->assertSee('Şirketler duruma göre')->assertSee('Kayıt alındı')->assertDontSee('Bekleyen KYC belgesi');
        $this->actingAs($admin)->get('/panel/raporlar?sekme=uyelik')->assertOk()->assertSee('Bekleyen KYC belgesi');
        $this->actingAs($ops)->get('/panel/raporlar?sekme=talepler')->assertOk()->assertSee('Dönüşüm');
        $this->actingAs($ops)->get('/panel/raporlar?sekme=bildirim')->assertOk()->assertSee('Kanal sağlığı')->assertSee('WhatsApp');
        $this->actingAs($ops)->get('/panel/raporlar?sekme=icerik')->assertOk()->assertSee('İçerik duruma göre');
        $this->actingAs($ops)->get('/panel/raporlar?sekme=yok')->assertOk()->assertSee('Onaylı tutar (30g)');
        $this->actingAs($owner)->withContext($acme)->get('/panel/raporlar')->assertForbidden();

        // Entegrasyonlar (performance.view): sağlayıcılar maskeli, kanal sağlığı, API notu.
        $this->actingAs($admin)->get('/panel/entegrasyonlar')->assertOk()->assertSee('WhatsApp (Meta Cloud API)')->assertSee('Kapalı')->assertSee('Bildirim kanalı sağlığı')->assertSee('public) API henüz yok')->assertDontSee('tok-');
        $this->actingAs($finance)->get('/panel/entegrasyonlar')->assertForbidden();

        // Üye dizini (tenant): görünürlük şirket listesiyle aynı; arama.
        $this->actingAs($owner)->withContext($acme)->get('/panel/uyeler')->assertOk()->assertSee('Üye dizini')->assertSee($owner->name)->assertSee('Sahip');
        $this->actingAs($owner)->withContext($acme)->get('/panel/uyeler?q=olmayan-kisi')->assertOk()->assertSee('Eşleşen üye yok');

        // Yerelleştirme = ayarlar genel grubu; diğer gruplar sayfada yok.
        $this->actingAs($admin)->get('/panel/ayarlar?grup=general')->assertOk()->assertSee('Yerelleştirme')->assertSee('Saat dilimi')->assertDontSee('Otomatik onay');
        $this->actingAs($admin)->get('/panel/ayarlar')->assertOk()->assertSee('Site & sistem ayarları')->assertSee('Otomatik onay');
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
