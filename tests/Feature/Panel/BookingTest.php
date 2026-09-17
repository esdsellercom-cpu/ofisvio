<?php

namespace Tests\Feature\Panel;

use App\Models\Booking;
use App\Models\Location;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Booking v1: odalar (geo.edit), uygunluk motoru, çakışma, müşteri paneli
 * (booking.view/create/cancel, şirket kapsamı), resepsiyon masası (lokasyon
 * kapsamı), operations_admin (global view + JIT'li override), tenant sınırı,
 * vitrin saat çipleri odalardan.
 */
class BookingTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Location $kadikoy;

    private Location $levent;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
        Carbon::setTestNow('2026-09-17 08:00:00');

        $this->kadikoy = Location::create(['name' => 'Kadıköy', 'slug' => 'kadikoy', 'city' => 'İstanbul', 'region' => 'Anadolu', 'is_active' => true, 'is_published' => true]);
        $this->levent = Location::create(['name' => 'Levent', 'slug' => 'levent', 'city' => 'İstanbul', 'region' => 'Avrupa', 'is_active' => true, 'is_published' => true]);
        $this->room = Room::create(['location_id' => $this->kadikoy->id, 'name' => 'Toplantı 1', 'kind' => 'meeting', 'capacity' => 6, 'hourly_rate' => 400, 'open_from' => '09:00', 'open_until' => '18:00', 'slot_minutes' => 60, 'max_hours' => 4]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function musteri_oda_rezerve_eder_cakisma_ve_kurallar_reddedilir_iptal_owner_ile(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $acmeCo);
        $employee = $this->member($acme);
        $this->grantRole($employee, 'employee', ['company_id' => $acmeCo->id]);
        $base = "/panel/sirketler/{$acmeCo->id}/rezervasyonlar";

        // Liste ve form; şirket ekranında bağlantı.
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}")->assertOk()->assertSee('Rezervasyonlar');
        $this->actingAs($owner)->withContext($acme)->get($base)->assertOk()->assertSee('Henüz rezervasyon yok');
        $this->actingAs($owner)->withContext($acme)->get("{$base}/yeni?oda={$this->room->id}&gun=2026-09-18")->assertOk()
            ->assertSee('data-booking-slot-btn="09:00"', false)->assertSee('Toplantı 1');

        // Çalışan rezerve eder (booking.create, employee).
        $this->actingAs($employee)->withContext($acme)->post($base, ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '10:00', 'hours' => 2, 'note' => 'Projektör'])
            ->assertRedirect($base)->assertSessionHasNoErrors();
        $booking = Booking::withoutTenantScope()->firstOrFail();
        $this->assertSame([$acmeCo->id, 'confirmed', 800, 'Projektör'], [(int) $booking->company_id, $booking->status, $booking->total_amount, $booking->note]);
        $this->assertSame('2026-09-18 12:00', $booking->ends_at->format('Y-m-d H:i'));

        // Çakışma: kısmi kesişme de reddedilir; bitişik saat kabul edilir.
        $this->actingAs($owner)->withContext($acme)->from("{$base}/yeni")->post($base, ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '11:00', 'hours' => 1])
            ->assertSessionHasErrors('start');
        $this->actingAs($owner)->withContext($acme)->post($base, ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '12:00', 'hours' => 1])->assertSessionHasNoErrors();
        $this->assertSame(2, Booking::withoutTenantScope()->count());

        // Uygunluk motoru: açık saat dışı, geçmiş, ufuk ötesi, süre üst sınırı, slot katı.
        foreach ([
            ['date' => '2026-09-18', 'start' => '17:00', 'hours' => 2],   // 19:00 > kapanış
            ['date' => '2026-09-17', 'start' => '07:00', 'hours' => 1],   // geçmiş (şimdi 08:00)
            ['date' => '2026-12-31', 'start' => '10:00', 'hours' => 1],   // 60 gün ötesi
            ['date' => '2026-09-19', 'start' => '09:00', 'hours' => 5],   // max 4 saat
            ['date' => '2026-09-19', 'start' => '09:00', 'hours' => 1.5], // 60 dk katı değil
        ] as $bad) {
            $this->actingAs($owner)->withContext($acme)->from("{$base}/yeni")->post($base, $bad + ['room_id' => $this->room->id])->assertSessionHasErrors('start');
        }
        $this->assertSame(2, Booking::withoutTenantScope()->count());

        // Slot tablosu dolu saatleri işaretler.
        $slots = collect(app(BookingService::class)->availability($this->room, Carbon::parse('2026-09-18')));
        $this->assertSame(['10:00', '11:00', '12:00'], $slots->where('taken', true)->pluck('start')->values()->all());
        $this->actingAs($owner)->withContext($acme)->get("{$base}/yeni?oda={$this->room->id}&gun=2026-09-18")->assertOk()
            ->assertSee('data-booking-slot-btn="10:00" disabled', false);

        // İptal: çalışan yapamaz (booking.cancel yok), owner yapar; 2 saatten az kala reddedilir.
        $this->actingAs($employee)->withContext($acme)->post("{$base}/{$booking->id}/iptal")->assertForbidden();
        $this->actingAs($owner)->withContext($acme)->post("{$base}/{$booking->id}/iptal", ['reason' => 'Toplantı ertelendi'])->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', 'Toplantı ertelendi', $owner->id], [$booking->fresh()->status, $booking->fresh()->cancel_reason, (int) $booking->fresh()->cancelled_by]);

        Carbon::setTestNow('2026-09-18 11:30:00');
        $late = Booking::withoutTenantScope()->where('status', 'confirmed')->firstOrFail(); // 12:00 başlıyor
        $this->actingAs($owner)->withContext($acme)->from($base)->post("{$base}/{$late->id}/iptal")->assertSessionHasErrors('booking');
        $this->assertSame('confirmed', $late->fresh()->status);

        // İptal edilen saat yeniden boşalır.
        $this->actingAs($owner)->withContext($acme)->post($base, ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '14:00', 'hours' => 1])->assertSessionHasNoErrors();
    }

    #[Test]
    public function tenant_siniri_kardes_sirket_ve_baska_organizasyon(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $acmeCo);
        $beta = $this->organization('Beta');
        $betaCo = $this->company($beta, 'Beta Ltd.');
        $betaOwner = $this->owner($beta, $betaCo);

        $this->actingAs($owner)->withContext($acme)->post("/panel/sirketler/{$acmeCo->id}/rezervasyonlar", ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '10:00', 'hours' => 1])->assertSessionHasNoErrors();
        $booking = Booking::withoutTenantScope()->firstOrFail();

        // Beta, Acme'nin şirketine ve rezervasyonuna ulaşamaz (404: varlık sızmaz).
        $this->actingAs($betaOwner)->withContext($beta)->get("/panel/sirketler/{$acmeCo->id}/rezervasyonlar")->assertNotFound();
        $this->actingAs($betaOwner)->withContext($beta)->post("/panel/sirketler/{$acmeCo->id}/rezervasyonlar/{$booking->id}/iptal")->assertNotFound();
        // Kendi şirketi üzerinden başkasının rezervasyon id'si: scopeBindings -> 404.
        $this->actingAs($betaOwner)->withContext($beta)->post("/panel/sirketler/{$betaCo->id}/rezervasyonlar/{$booking->id}/iptal")->assertNotFound();
        // Beta kendi listesinde Acme'nin kaydını görmez ama aynı odayı o saatte alamaz (çakışma şirketler arası).
        $this->actingAs($betaOwner)->withContext($beta)->get("/panel/sirketler/{$betaCo->id}/rezervasyonlar")->assertOk()->assertDontSee('Acme');
        $this->actingAs($betaOwner)->withContext($beta)->from('/')->post("/panel/sirketler/{$betaCo->id}/rezervasyonlar", ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '10:00', 'hours' => 1])->assertSessionHasErrors('start');
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    #[Test]
    public function resepsiyon_kendi_lokasyon_masasini_gorur_ve_acar_operations_admin_jit_ile_kural_disi(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $this->owner($acme, $acmeCo);

        $reception = User::factory()->create();
        $this->grantRole($reception, 'reception', ['location_id' => $this->kadikoy->id]);

        // Resepsiyon internal roldür: 2FA'sız panele giremez; 2FA sonrası kendi masası açılır, diğer şube 403.
        $this->actingAs($reception)->get("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}")->assertRedirect('/panel/hesap/guvenlik');
        $reception->forceFill(['two_factor_secret' => encrypt('x'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($reception)->get("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}?gun=2026-09-18")->assertOk()
            ->assertSee('Kadıköy masası')->assertSee('Masadan rezervasyon')->assertSee('Acme A.Ş.')->assertDontSee('Kural dışı');
        $this->actingAs($reception)->get("/panel/rezervasyonlar/lokasyon/{$this->levent->slug}")->assertForbidden();
        $this->actingAs($reception)->get('/panel/rezervasyonlar')->assertForbidden(); // global booking.view yok
        $this->actingAs($reception)->get('/panel/hesap')->assertOk()->assertSee('Kadıköy masası'); // menü

        // Masadan rezervasyon (booking.create, lokasyon); başka şubenin odası reddedilir.
        $this->actingAs($reception)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}", ['company_id' => $acmeCo->id, 'room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '09:00', 'hours' => 1])
            ->assertRedirect("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}?gun=2026-09-18")->assertSessionHasNoErrors();
        $this->assertSame((int) $reception->id, (int) Booking::withoutTenantScope()->firstOrFail()->booked_by);
        $this->actingAs($reception)->from('/')->post("/panel/rezervasyonlar/lokasyon/{$this->levent->slug}", ['company_id' => $acmeCo->id, 'room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '11:00', 'hours' => 1])->assertForbidden();
        // Resepsiyon kural dışı açamaz ve iptal edemez (admin_override yok).
        $this->actingAs($reception)->from('/')->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}", ['company_id' => $acmeCo->id, 'room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '20:00', 'hours' => 1, 'override' => 1])->assertSessionHasErrors('start');
        $booking = Booking::withoutTenantScope()->firstOrFail();
        $this->actingAs($reception)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}/{$booking->id}/iptal", ['reason' => 'müşteri aradı'])->assertForbidden();

        // operations_admin: genel liste + her masa; override JIT ister.
        $ops = $this->staff('operations_admin');
        $this->actingAs($ops)->get('/panel/rezervasyonlar')->assertOk()->assertSee('Acme A.Ş.')->assertSee('Kadıköy')->assertSee('Levent');
        $this->actingAs($ops)->get('/panel/rezervasyonlar?lokasyon='.$this->levent->id)->assertOk()->assertDontSee('Acme A.Ş.');
        $this->actingAs($ops)->get("/panel/rezervasyonlar/lokasyon/{$this->levent->slug}")->assertOk()->assertSee('Kural dışı erişim (JIT)');
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}/{$booking->id}/iptal", ['reason' => 'oda arızası'])->assertForbidden();
        // booking.create taşımaz: JIT'siz masadan açamaz.
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}", ['company_id' => $acmeCo->id, 'room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '13:00', 'hours' => 1])->assertForbidden();

        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}/jit", ['reason' => 'gece etkinliği için oda açılacak', 'ttl_minutes' => 30])
            ->assertRedirect("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}")->assertSessionHasNoErrors();
        // Grant Kadıköy'e özel: Levent'te iptal hâlâ 403.
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/{$this->levent->slug}/{$booking->id}/iptal", ['reason' => 'oda arızası'])->assertForbidden();
        // Kural dışı: açık saat dışında (20:00) rezervasyon; çakışma yine reddedilir.
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}", ['company_id' => $acmeCo->id, 'room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '20:00', 'hours' => 1, 'override' => 1])->assertSessionHasNoErrors();
        $this->assertTrue(Booking::withoutTenantScope()->where('overridden', true)->exists());
        $this->actingAs($ops)->from('/')->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}", ['company_id' => $acmeCo->id, 'room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '09:00', 'hours' => 1, 'override' => 1])->assertSessionHasErrors('start');
        // Masadan iptal (JIT) — süre kuralı yok.
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}/{$booking->id}/iptal", ['reason' => 'oda arızası'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $booking->fresh()->status);
        // Denetim: JIT grant kaydı.
        $this->actingAs($this->staff('system_admin'))->get('/panel/denetim?tur=jit')->assertOk()->assertSee('booking.admin_override');
    }

    #[Test]
    public function odalar_geo_edit_ile_yonetilir_ve_vitrin_saatleri_odalardan_gelir(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // geo.edit var
        $base = "/panel/geo/lokasyon/{$this->kadikoy->slug}/odalar";

        $this->actingAs($admin)->get("/panel/geo/lokasyon/{$this->kadikoy->slug}")->assertOk()->assertSee('Odalar');
        $this->actingAs($ops)->get($base)->assertOk()->assertSee('Toplantı 1')->assertSee('Yeni oda');

        // Ekle: saat tutarsızlığı reddedilir; geçerli kayıt eklenir.
        $room = ['name' => 'Etkinlik', 'kind' => 'event', 'capacity' => 40, 'hourly_rate' => 1500, 'open_from' => '10:00', 'open_until' => '09:00', 'slot_minutes' => 60, 'max_hours' => 8, 'is_active' => 1];
        $this->actingAs($ops)->from($base)->post($base, $room)->assertSessionHasErrors('open_until');
        $this->actingAs($ops)->post($base, ['open_until' => '22:00'] + $room)->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertSame(2, Room::count());

        // Vitrin: saat çipleri iki odanın birleşimi (09:00..21:00), kodda sabit liste yok.
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('data-booking-slot-btn="09:00"', $home);
        $this->assertStringContainsString('data-booking-slot-btn="21:00"', $home);
        $this->assertStringNotContainsString('data-booking-slot-btn="22:00"', $home);

        // Pasif oda müşteri seçiminden düşer; başka şubenin odası bu lokasyondan güncellenemez (404).
        $event = Room::where('name', 'Etkinlik')->firstOrFail();
        $this->actingAs($ops)->put("{$base}/{$event->id}", ['open_until' => '22:00', 'is_active' => 0] + $room)->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertFalse($event->fresh()->is_active);
        $this->assertSame(['Toplantı 1'], app(BookingService::class)->bookableRooms()->pluck('name')->all());
        $this->actingAs($ops)->put("/panel/geo/lokasyon/{$this->levent->slug}/odalar/{$event->id}", $room)->assertNotFound();

        // Gelecek rezervasyonu olan oda silinemez; iptal sonrası silinir. geo.edit olmayan personel 403.
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $acmeCo);
        $this->actingAs($owner)->withContext($acme)->post("/panel/sirketler/{$acmeCo->id}/rezervasyonlar", ['room_id' => $this->room->id, 'date' => '2026-09-20', 'start' => '10:00', 'hours' => 1])->assertSessionHasNoErrors();
        $this->actingAs($ops)->from($base)->delete("{$base}/{$this->room->id}")->assertSessionHasErrors('room');
        $this->actingAs($ops)->delete("{$base}/{$event->id}")->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertSame(1, Room::count());
        $this->actingAs($this->staff('finance_admin'))->get($base)->assertForbidden();
    }
}
