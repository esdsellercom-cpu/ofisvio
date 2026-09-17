<?php

namespace Tests\Feature\Panel;

use App\Enums\BookingStatus;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\LocationMedia;
use App\Models\Media;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use App\Models\Website;
use App\Services\BookingService;
use App\Services\InvoiceService;
use App\Services\SettingsService;
use App\Services\SpaceService;
use Database\Seeders\NotificationRuleSeeder;
use Database\Seeders\WebsiteSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 45 — Coworking operasyon sertleştirme: çift rezervasyon savunması (oda kilidi + dilim tekil kısıtı),
 * kapasite, bakım durumu (oda/lokasyon/alan), rezervasyon sınırları, fiyat/KDV/indirim/ödeme durumu,
 * olanaklar + kapak görseli doğrulaması, yeniden planlamada dilim senkronu, denetim izi.
 */
class OperationsHardeningTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Location $kadikoy;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->seed(NotificationRuleSeeder::class);
        Carbon::setTestNow('2026-09-17 08:00:00');
        app(SettingsService::class)->set(null, 'booking.auto_confirm', true);

        $this->kadikoy = Location::create(['name' => 'Kadıköy', 'slug' => 'kadikoy', 'city' => 'İstanbul', 'region' => 'Anadolu', 'is_active' => true, 'is_published' => true]);
        $this->room = Room::create(['location_id' => $this->kadikoy->id, 'name' => 'Toplantı 1', 'kind' => 'meeting', 'capacity' => 6, 'hourly_rate' => 40000, 'open_from' => '09:00', 'open_until' => '18:00', 'slot_minutes' => 60, 'max_hours' => 4])->refresh(); // veritabanı varsayılanları (is_active) yüklensin
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Company, 1: User} */
    private function customer(string $name = 'Acme'): array
    {
        $org = $this->organization($name);
        $co = $this->company($org, $name.' A.Ş.');

        return [$co, $this->owner($org, $co)];
    }

    #[Test]
    public function ayni_saat_iki_kez_rezerve_edilemez_dilim_kilidi_son_savunmadir(): void
    {
        [$acmeCo, $owner] = $this->customer('Acme');
        [$betaCo, $betaOwner] = $this->customer('Beta');
        $svc = app(BookingService::class);

        $first = $svc->book($owner, $acmeCo, $this->room, ['date' => '2026-09-18', 'start' => '10:00', 'hours' => 2]);
        $this->assertSame(BookingStatus::CONFIRMED, $first->status);
        // 2 saat = 8 dilim (15 dk) yazıldı.
        $this->assertSame(8, BookingSlot::query()->where('booking_id', $first->id)->count());

        // Aynı oda, kesişen aralık — başka şirket: çakışma sorgusu reddeder.
        foreach ([['10:00', 1], ['11:00', 2], ['09:00', 2]] as [$start, $hours]) {
            try {
                $svc->book($betaOwner, $betaCo, $this->room, ['date' => '2026-09-18', 'start' => $start, 'hours' => $hours]);
                $this->fail('Çakışan rezervasyon kabul edildi: '.$start);
            } catch (DomainException $e) {
                $this->assertStringContainsString('dolu', $e->getMessage());
            }
        }

        // Son savunma: çakışma sorgusunu atlatan bir yarış (phantom) varsayımıyla dilim tekil kısıtı —
        // aynı dilim doğrudan tabloda olsa da kayıt oluşmaz, işlem geri alınır.
        BookingSlot::query()->where('booking_id', $first->id)->delete(); // birinci kayıt kalır, dilimleri kaldırılır
        $svc->transition($first, BookingStatus::CANCELLED, $owner, 'test'); // sorgu artık çakışma görmez
        BookingSlot::query()->insert(['room_id' => $this->room->id, 'booking_id' => $first->id, 'slot_at' => '2026-09-18 14:15:00']); // yabancı dilim

        try {
            $svc->book($betaOwner, $betaCo, $this->room, ['date' => '2026-09-18', 'start' => '14:00', 'hours' => 1]);
            $this->fail('Dilim tekil kısıtı çalışmadı.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('az önce başka bir rezervasyonla', $e->getMessage());
        }

        $this->assertSame(1, Booking::withoutTenantScope()->count(), 'Başarısız işlem kayıt bırakmamalı (transaction geri alındı).');
        $this->assertSame(0, Booking::withoutTenantScope()->whereIn('status', BookingStatus::blockingValues())->count());

        // Bitişik saat serbest; iptal edilen rezervasyonun dilimleri silinmiş (tekrar rezerve edilebilir).
        $again = $svc->book($betaOwner, $betaCo, $this->room, ['date' => '2026-09-18', 'start' => '10:00', 'hours' => 1]);
        $this->assertSame(4, BookingSlot::query()->where('booking_id', $again->id)->count());
        $this->assertSame(0, BookingSlot::query()->where('booking_id', $first->id)->where('slot_at', '<', '2026-09-18 14:00:00')->count());

        // Yeniden planlama dilimleri taşır; eski saat serbest kalır, yeni saat tutulur.
        $ops = $this->staff('operations_admin');
        $svc->reschedule($ops, $again, Carbon::parse('2026-09-19 09:00'), 120);
        $this->assertSame(8, BookingSlot::query()->where('booking_id', $again->id)->whereDate('slot_at', '2026-09-19')->count());
        $this->assertSame(0, BookingSlot::query()->where('booking_id', $again->id)->whereDate('slot_at', '2026-09-18')->count());
        $svc->book($owner, $acmeCo, $this->room, ['date' => '2026-09-18', 'start' => '10:00', 'hours' => 1]); // eski saat yeniden alınabilir

        // Slot süresi 15 dk'nın katı olmalı (dilim kilidi buna dayanır).
        try {
            $svc->createRoom($ops, $this->kadikoy, ['name' => 'Bozuk', 'kind' => 'meeting', 'capacity' => 2, 'hourly_rate' => 0, 'open_from' => '09:00', 'open_until' => '18:00', 'slot_minutes' => 20, 'max_hours' => 2]);
            $this->fail('20 dk slot kabul edildi.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('15 dakikanın katı', $e->getMessage());
        }
    }

    #[Test]
    public function kapasite_bakim_ve_rezervasyon_sinirlari(): void
    {
        [$acmeCo, $owner] = $this->customer('Acme');
        $svc = app(BookingService::class);
        $ops = $this->staff('operations_admin');

        // Kapasite: 6 kişilik odaya 7 katılımcı olmaz; override (JIT) geçer.
        try {
            $svc->book($owner, $acmeCo, $this->room, ['date' => '2026-09-18', 'start' => '10:00', 'hours' => 1, 'participants' => 7]);
            $this->fail('Kapasite aşımı kabul edildi.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('kapasitesi 6', $e->getMessage());
        }
        $svc->book($ops, $acmeCo, $this->room, ['date' => '2026-09-18', 'start' => '10:00', 'hours' => 1, 'participants' => 7], override: true);

        // Bakım: oda bakımdayken rezervasyon kapalı, uygunluk tablosu dolu, vitrin listesi odayı göstermez; bakım bitince açılır.
        $svc->updateRoom($ops, $this->room, ['maintenance_until' => '2026-09-20', 'maintenance_note' => 'Klima değişimi']);
        $this->assertSame('maintenance', $this->room->fresh()->operationalStatus());
        $this->assertTrue(collect($svc->availability($this->room->fresh(), Carbon::parse('2026-09-19')))->every(fn (array $s) => $s['taken']));
        $this->assertFalse(collect($svc->availability($this->room->fresh(), Carbon::parse('2026-09-21')))->every(fn (array $s) => $s['taken']));
        $this->assertCount(0, $svc->bookableRooms());

        try {
            $svc->book($owner, $acmeCo, $this->room->fresh(), ['date' => '2026-09-19', 'start' => '10:00', 'hours' => 1]);
            $this->fail('Bakımdaki oda rezerve edildi.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Klima değişimi', $e->getMessage());
        }
        $svc->book($owner, $acmeCo, $this->room->fresh(), ['date' => '2026-09-21', 'start' => '10:00', 'hours' => 1]); // bakım sonrası
        $this->get('/rezervasyon?lokasyon='.$this->kadikoy->id.'&gun=2026-09-19')->assertOk()->assertDontSee('data-booking-slot-btn', false);

        // Bakım tarihi geçmişe konamaz; bakım kaldırılınca liste geri gelir. Lokasyon bakımı tüm odaları kapatır.
        try {
            $svc->updateRoom($ops, $this->room->fresh(), ['maintenance_until' => '2026-09-01', 'maintenance_note' => 'x']);
            $this->fail('Geçmiş bakım tarihi kabul edildi.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('bugünden önce', $e->getMessage());
        }
        $svc->updateRoom($ops, $this->room->fresh(), ['maintenance_until' => null, 'maintenance_note' => null]);
        $this->assertCount(1, $svc->bookableRooms());
        $this->kadikoy->forceFill(['maintenance_until' => '2026-09-25', 'maintenance_note' => 'Tadilat'])->save();
        $this->assertCount(0, $svc->bookableRooms());

        try {
            $svc->book($owner, $acmeCo, $this->room->fresh(), ['date' => '2026-09-22', 'start' => '14:00', 'hours' => 1]);
            $this->fail('Bakımdaki lokasyonda rezervasyon alındı.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Tadilat', $e->getMessage());
        }
        $this->kadikoy->forceFill(['maintenance_until' => null, 'maintenance_note' => null])->save();

        // Sınırlar: şirket başına 2 açık rezervasyon (zaten 2 var: 18 ve 21 Eylül), günlük 1.
        app(SettingsService::class)->set(null, 'booking.max_active_per_company', 2);
        try {
            $svc->book($owner, $acmeCo, $this->room->fresh(), ['date' => '2026-09-22', 'start' => '14:00', 'hours' => 1]);
            $this->fail('Açık rezervasyon sınırı uygulanmadı.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Açık rezervasyon sınırına', $e->getMessage());
        }
        app(SettingsService::class)->set(null, 'booking.max_active_per_company', 0);
        app(SettingsService::class)->set(null, 'booking.max_per_day', 1);
        try {
            $svc->book($owner, $acmeCo, $this->room->fresh(), ['date' => '2026-09-21', 'start' => '14:00', 'hours' => 1]);
            $this->fail('Günlük sınır uygulanmadı.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Aynı gün', $e->getMessage());
        }
        $svc->book($ops, $acmeCo, $this->room->fresh(), ['date' => '2026-09-21', 'start' => '14:00', 'hours' => 1], override: true); // JIT sınırı aşar
        $this->assertSame(3, Booking::withoutTenantScope()->count());

        // Alan (masa/ofis) bakımı: tahsis reddedilir.
        $desk = Space::create(['location_id' => $this->kadikoy->id, 'kind' => 'desk_fixed', 'name' => 'A-1', 'capacity' => 1, 'monthly_price' => 300000, 'is_active' => true, 'maintenance_until' => '2026-09-30', 'maintenance_note' => 'Masa değişimi']);
        try {
            app(SpaceService::class)->assign($ops, $desk, $acmeCo, ['starts_on' => '2026-09-18']);
            $this->fail('Bakımdaki alana tahsis yapıldı.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Masa değişimi', $e->getMessage());
        }
    }

    #[Test]
    public function fiyat_kdv_indirim_ve_odeme_durumu_faturayla_senkron(): void
    {
        [$acmeCo, $owner] = $this->customer('Acme');
        $svc = app(BookingService::class);
        $ops = $this->staff('operations_admin');
        app(SettingsService::class)->set(null, 'finance.default_tax_rate', 20);

        $b = $svc->book($owner, $acmeCo, $this->room, ['date' => '2026-09-18', 'start' => '10:00', 'hours' => 2]);
        $this->assertSame([80000, 0, 20, 16000, 96000, 'unpaid'], [$b->total_amount, $b->discount_amount, $b->tax_rate, $b->tax_amount, $b->grandTotal(), $b->payment_status]);

        // Onaylı şirket rezervasyonu fatura üretti (dinleyici): fatura brüt = rezervasyon net.
        $invoice = Invoice::withoutTenantScope()->where('booking_id', $b->id)->firstOrFail();
        $this->assertSame([80000, 96000, 'issued'], [$invoice->subtotal, $invoice->total, $invoice->status]);

        // İndirim (booking.manage rotası): net ve KDV yeniden; audit; brütü aşan indirim reddedilir; ödenmişe uygulanmaz.
        $this->actingAs($ops)->from("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}")->put("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}/indirim", ['discount' => '900,00', 'reason' => 'fazla'])->assertSessionHasErrors('discount');
        $this->actingAs($ops)->put("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}/indirim", ['discount' => '200,00', 'reason' => 'Üye indirimi'])->assertRedirect("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}")->assertSessionHasNoErrors();
        $b = $b->fresh();
        $this->assertSame([60000, 20000, 12000, 72000, 'Üye indirimi'], [$b->total_amount, $b->discount_amount, $b->tax_amount, $b->grandTotal(), $b->discount_reason]);
        $this->assertTrue(AuditLog::query()->where('action', 'booking.discounted')->where('entity_id', $b->id)->exists());
        $this->actingAs($ops)->get("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}")->assertOk()->assertSee('indirim')->assertSee('Ödenmedi');

        // Tam indirim → ücretsiz; tahsilat: fatura ödenince rezervasyon 'paid', iptal edilince 'unpaid'.
        $svc->applyDiscount($ops, $b, 80000, 'Etkinlik ortağı');
        $this->assertSame(['waived', 0, 0], [$b->fresh()->payment_status, $b->fresh()->total_amount, $b->fresh()->tax_amount]);
        $svc->applyDiscount($ops, $b->fresh(), 0, 'Geri alındı');
        $this->assertSame('unpaid', $b->fresh()->payment_status);

        $finance = $this->staff('finance_admin');
        app(InvoiceService::class)->recordPayment($finance, $invoice->fresh(), ['amount' => '500,00', 'method' => 'transfer', 'paid_on' => '2026-09-17']);
        $this->assertSame('partial', $b->fresh()->payment_status);
        app(InvoiceService::class)->recordPayment($finance, $invoice->fresh(), ['amount' => '460,00', 'method' => 'transfer', 'paid_on' => '2026-09-17']);
        $this->assertSame(['paid', true], [$b->fresh()->payment_status, $b->fresh()->paid_at !== null]);

        try {
            $svc->applyDiscount($ops, $b->fresh(), 1000, 'geç');
            $this->fail('Ödenmiş rezervasyona indirim uygulandı.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Tahsilatı başlamış', $e->getMessage());
        }

        // Yeniden planlama: yeni süre brütü, mevcut indirim korunur, KDV yeniden.
        $c = $svc->book($owner, $acmeCo, $this->room, ['date' => '2026-09-19', 'start' => '10:00', 'hours' => 1]);
        $svc->applyDiscount($ops, $c, 10000, 'Sadakat');
        $svc->reschedule($ops, $c, Carbon::parse('2026-09-19 13:00'), 180);
        $this->assertSame([110000, 10000, 22000], [$c->fresh()->total_amount, $c->fresh()->discount_amount, $c->fresh()->tax_amount]);
        $this->actingAs($owner)->withContext($acmeCo->organization)->get("/panel/sirketler/{$acmeCo->id}/rezervasyonlar")->assertOk()->assertSee('KDV dahil');
    }

    #[Test]
    public function olanaklar_kapak_gorseli_ve_bakim_alanlari_formdan_yonetilir(): void
    {
        $ops = $this->staff('operations_admin');
        $site = Website::query()->default()->firstOrFail();
        $media = Media::create(['website_id' => $site->id, 'uploaded_by' => $ops->id, 'disk' => 'public', 'path' => 'media/x.jpg', 'original_name' => 'x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 100, 'width' => 800, 'height' => 600, 'checksum_sha256' => str_repeat('a', 64), 'alt' => 'Toplantı odası', 'status' => Media::STATUS_APPROVED]);
        $foreign = Media::create(['website_id' => $site->id, 'uploaded_by' => $ops->id, 'disk' => 'public', 'path' => 'media/y.jpg', 'original_name' => 'y.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 100, 'width' => 800, 'height' => 600, 'checksum_sha256' => str_repeat('b', 64), 'status' => Media::STATUS_APPROVED]);
        LocationMedia::create(['location_id' => $this->kadikoy->id, 'media_id' => $media->id, 'category' => 'gallery', 'sort_order' => 1]);

        $base = ['name' => 'Toplantı 2', 'kind' => 'meeting', 'capacity' => 8, 'hourly_rate' => '500', 'open_from' => '09:00', 'open_until' => '18:00', 'slot_minutes' => 30, 'max_hours' => 4, 'is_active' => 1];

        // Galeri dışı medya reddedilir; galeri görseli + olanaklar + bakım kaydedilir; liste rozeti.
        $this->actingAs($ops)->from('/panel/geo/lokasyon/kadikoy/odalar')->post('/panel/geo/lokasyon/kadikoy/odalar', $base + ['cover_media_id' => $foreign->id])->assertSessionHasErrors('open_until');
        $this->actingAs($ops)->post('/panel/geo/lokasyon/kadikoy/odalar', $base + ['cover_media_id' => $media->id, 'amenities' => 'Projektör, Beyaz tahta,Projektör , Klima', 'maintenance_until' => '2026-09-20', 'maintenance_note' => 'Boya'])->assertRedirect()->assertSessionHasNoErrors();
        $room = Room::query()->where('name', 'Toplantı 2')->firstOrFail();
        $this->assertSame(['Projektör', 'Beyaz tahta', 'Klima'], $room->amenityList());
        $this->assertSame($media->id, $room->cover_media_id);
        $this->assertSame('maintenance', $room->operationalStatus());
        $this->actingAs($ops)->get('/panel/geo/lokasyon/kadikoy/odalar')->assertOk()->assertSee('Bakımda · 20.09.2026')->assertSee('Projektör, Beyaz tahta, Klima');
        $this->actingAs($ops)->from('/panel/geo/lokasyon/kadikoy/odalar')->post('/panel/geo/lokasyon/kadikoy/odalar', $base + ['name' => 'Eksik', 'maintenance_until' => '2026-09-20'])->assertSessionHasErrors('maintenance_note');

        // Alan (masa/ofis) aynı alanlar; lokasyon bakım alanı künye formunda.
        $this->actingAs($ops)->post('/panel/geo/lokasyon/kadikoy/alanlar', ['kind' => 'office', 'name' => 'Ofis 1', 'capacity' => 4, 'monthly_price' => '12000', 'is_active' => 1, 'amenities' => 'Kilitli kapı, Pencere', 'cover_media_id' => $media->id])->assertRedirect()->assertSessionHasNoErrors();
        $space = Space::query()->where('name', 'Ofis 1')->firstOrFail();
        $this->assertSame(['Kilitli kapı', 'Pencere'], $space->amenityList());
        $this->actingAs($ops)->from('/panel/geo/lokasyon/kadikoy/alanlar')->post('/panel/geo/lokasyon/kadikoy/alanlar', ['kind' => 'office', 'name' => 'Ofis 2', 'cover_media_id' => $foreign->id])->assertSessionHasErrors('name');
        $this->actingAs($ops)->put('/panel/geo/lokasyon/kadikoy/kunye', ['name' => 'Kadıköy', 'city' => 'İstanbul', 'is_active' => 1, 'maintenance_until' => '2026-09-30', 'maintenance_note' => 'Tadilat'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Tadilat', $this->kadikoy->fresh()->maintenance_note);
        $this->actingAs($ops)->from('/panel/geo/lokasyon/kadikoy')->put('/panel/geo/lokasyon/kadikoy/kunye', ['name' => 'Kadıköy', 'is_active' => 1, 'maintenance_until' => '2020-01-01', 'maintenance_note' => 'x'])->assertSessionHasErrors('maintenance_until');

        // Yetki: geo.edit olmayan personel oda/alan yazamaz.
        $this->actingAs($this->staff('finance_admin'))->post('/panel/geo/lokasyon/kadikoy/odalar', $base)->assertForbidden();
    }
}
