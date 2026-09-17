<?php

namespace Tests\Feature\Panel;

use App\Enums\BookingStatus;
use App\Integrations\UrlGuard;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\NotificationRecipient;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SettingsService;
use Database\Seeders\NotificationRuleSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Booking engine v2: odalar (geo.edit), uygunluk motoru + tampon, çakışma kilidi,
 * onay politikası (ayar), durum makinesi, müşteri paneli (şirket kapsamı), resepsiyon
 * masası (lokasyon kapsamı), operations_admin (onay/red/işletim + JIT override),
 * tenant sınırı, vitrin uçtan uca akış (§57) + WhatsApp/e-posta/uygulama içi bildirim
 * + denetim izi, süre dolumu, vitrin oda listesi/rozet.
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
        $this->seed(NotificationRuleSeeder::class);
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

    private function autoConfirm(bool $on): void
    {
        app(SettingsService::class)->set(null, 'booking.auto_confirm', $on);
    }

    #[Test]
    public function musteri_paneli_talep_onay_politikasi_cakisma_kurallar_ve_iptal(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $acmeCo);
        $employee = $this->member($acme);
        $this->grantRole($employee, 'employee', ['company_id' => $acmeCo->id]);
        $base = "/panel/sirketler/{$acmeCo->id}/rezervasyonlar";

        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}")->assertOk()->assertSee('Rezervasyonlar');
        $this->actingAs($owner)->withContext($acme)->get($base)->assertOk()->assertSee('Henüz rezervasyon yok');
        $this->actingAs($owner)->withContext($acme)->get("{$base}/yeni?oda={$this->room->id}&gun=2026-09-18")->assertOk()
            ->assertSee('data-booking-slot-btn="09:00"', false)->assertSee('Toplantı 1')->assertSee('aynı gün teyit');

        // Varsayılan politika: yönetici onayı → PENDING_APPROVAL, referans no, expires_at, geçmiş + audit.
        $this->actingAs($employee)->withContext($acme)->post($base, ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '10:00', 'hours' => 2, 'note' => 'Projektör', 'participants' => 4])
            ->assertRedirect($base)->assertSessionHasNoErrors();
        $booking = Booking::withoutTenantScope()->firstOrFail();
        $this->assertSame([$acmeCo->id, BookingStatus::PENDING_APPROVAL, 800, 'Projektör', 4, 'panel'], [(int) $booking->company_id, $booking->status, $booking->total_amount, $booking->note, $booking->participant_count, $booking->source]);
        $this->assertSame('OV-2026-'.sprintf('%06d', $booking->id), $booking->reference);
        $this->assertNotNull($booking->expires_at);
        $this->assertSame(['REQUESTED', 'PENDING_APPROVAL'], $booking->history->pluck('to_status')->map(fn ($s) => $s->value)->all());
        $this->assertTrue(AuditLog::query()->where('entity_type', 'booking')->where('entity_id', $booking->id)->where('action', 'booking.created')->exists());

        // Bekleyen talep de odayı tutar (pending hold): çakışma reddedilir; bitişik saat kabul.
        $this->actingAs($owner)->withContext($acme)->from("{$base}/yeni")->post($base, ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '11:00', 'hours' => 1])->assertSessionHasErrors('start');
        $this->actingAs($owner)->withContext($acme)->post($base, ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '12:00', 'hours' => 1])->assertSessionHasNoErrors();
        $this->assertSame(2, Booking::withoutTenantScope()->count());

        // Uygunluk motoru: açık saat dışı, en az 2 sa önce (ayar), 60 gün ufku (ayar), üst sınır, slot katı.
        foreach ([
            ['date' => '2026-09-18', 'start' => '17:00', 'hours' => 2],
            ['date' => '2026-09-17', 'start' => '09:00', 'hours' => 1],   // şimdi 08:00 → 2 saatten az
            ['date' => '2026-12-31', 'start' => '10:00', 'hours' => 1],
            ['date' => '2026-09-19', 'start' => '09:00', 'hours' => 5],
            ['date' => '2026-09-19', 'start' => '09:00', 'hours' => 1.5],
        ] as $bad) {
            $this->actingAs($owner)->withContext($acme)->from("{$base}/yeni")->post($base, $bad + ['room_id' => $this->room->id])->assertSessionHasErrors('start');
        }
        $this->assertSame(2, Booking::withoutTenantScope()->count());

        // Politika değişince davranış değişir: otomatik onay + tampon 30 dk.
        $this->autoConfirm(true);
        app(SettingsService::class)->set(null, 'booking.buffer_minutes', 30);
        $this->actingAs($owner)->withContext($acme)->post($base, ['room_id' => $this->room->id, 'date' => '2026-09-19', 'start' => '10:00', 'hours' => 1])->assertSessionHasNoErrors();
        $auto = Booking::withoutTenantScope()->orderByDesc('id')->firstOrFail();
        $this->assertSame(BookingStatus::CONFIRMED, $auto->status);
        $this->assertFalse($auto->approval_required);
        // Tampon iki yönlü: 09:30–11:30 penceresi → 09:00 ve 11:00 slotları da dolu, 12:00 serbest.
        $this->actingAs($owner)->withContext($acme)->from("{$base}/yeni")->post($base, ['room_id' => $this->room->id, 'date' => '2026-09-19', 'start' => '11:00', 'hours' => 1])->assertSessionHasErrors('start');
        $slots = collect(app(BookingService::class)->availability($this->room, Carbon::parse('2026-09-19')));
        $this->assertSame(['09:00', '10:00', '11:00'], $slots->where('taken', true)->pluck('start')->values()->all());
        app(SettingsService::class)->set(null, 'booking.buffer_minutes', 0);

        // İptal: çalışan yapamaz; owner yapar (bekleyen talep de iptal edilebilir); 2 saatten az kala reddedilir.
        $this->actingAs($employee)->withContext($acme)->post("{$base}/{$booking->id}/iptal")->assertForbidden();
        $this->actingAs($owner)->withContext($acme)->post("{$base}/{$booking->id}/iptal", ['reason' => 'Toplantı ertelendi'])->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertSame([BookingStatus::CANCELLED, 'Toplantı ertelendi', $owner->id], [$booking->fresh()->status, $booking->fresh()->cancel_reason, (int) $booking->fresh()->cancelled_by]);

        Carbon::setTestNow('2026-09-18 11:30:00');
        $late = Booking::withoutTenantScope()->where('starts_at', '2026-09-18 12:00:00')->firstOrFail();
        $this->actingAs($owner)->withContext($acme)->from($base)->post("{$base}/{$late->id}/iptal")->assertSessionHasErrors('booking');
        $this->assertTrue($late->fresh()->isActive());

        // İptal edilen saat yeniden boşalır.
        $this->actingAs($owner)->withContext($acme)->post($base, ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '14:00', 'hours' => 1])->assertSessionHasNoErrors();
    }

    #[Test]
    public function vitrin_uctan_uca_talep_whatsapp_onay_musteri_bildirimi_ve_denetim(): void
    {
        // §57: müşteri sitede → gerçek odalar → uygunluk → talep → PENDING_APPROVAL → yönetici WhatsApp/uygulama içi/e-posta
        // → onay → CONFIRMED → müşteri e-posta → takvim dolu → audit. Sağlayıcı: Http::fake (gerçek adaptör, kod yolu üretimle aynı).
        config(['integrations.providers.whatsapp.enabled' => true, 'integrations.providers.whatsapp.secrets' => ['access_token' => 'tok-test', 'phone_number_id' => '1234567890']]);
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['157.240.1.1']));
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST1']]], 200)]);

        $ops = $this->staff('operations_admin');
        NotificationRecipient::create(['name' => 'Rezervasyon yönetimi', 'channel' => 'whatsapp', 'address' => '+903326060999', 'group' => 'booking_managers', 'is_active' => true]);
        NotificationRecipient::create(['name' => 'Ops', 'channel' => 'in_app', 'user_id' => $ops->id, 'group' => 'booking_managers', 'is_active' => true]);
        NotificationRecipient::create(['name' => 'Ops e-posta', 'channel' => 'email', 'address' => 'ops@ofisvio.test', 'group' => 'booking_managers', 'is_active' => true]);

        // Vitrin: ana sayfa gerçek odayı ve politika rozetini basar; ön talep formu değil rezervasyon akışı.
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('Toplantı 1', $home);
        $this->assertStringContainsString('aynı gün teyit', $home);
        $this->assertStringContainsString('action="http://localhost/rezervasyon"', $home);
        $this->assertStringNotContainsString('name="kind" value="booking"', $home);

        // Uygunluk sayfası: lokasyon → oda → slot çipleri (canlı).
        $this->get('/rezervasyon?lokasyon='.$this->kadikoy->id.'&gun=2026-09-18')->assertOk()
            ->assertSee('Toplantı 1')->assertSee('data-booking-slot-btn="14:00"', false)->assertSee('400 ₺/saat');
        $this->get('/rezervasyon?lokasyon='.$this->levent->id)->assertOk()->assertSee('rezervasyona açık oda yok');

        // Talep: KVKK zorunlu, bot tuzağı, telefon E.164.
        $form = ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '14:00', 'hours' => 1, 'participants' => 4, 'name' => 'Ahmet Yılmaz', 'email' => 'ahmet@ornek.com', 'phone' => '+905321112233', 'company_name' => 'Yılmaz Ltd.', 'note' => 'Projektör', 'kvkk' => '1'];
        $this->from('/rezervasyon')->post('/rezervasyon', ['kvkk' => null] + $form)->assertSessionHasErrors('kvkk');
        $this->from('/rezervasyon')->post('/rezervasyon', ['website' => 'bot'] + $form)->assertSessionHasErrors('website');
        $this->from('/rezervasyon')->post('/rezervasyon', ['phone' => '0532 111 22 33'] + $form)->assertSessionHasErrors('phone');
        $this->assertSame(0, Booking::withoutTenantScope()->count());

        $response = $this->post('/rezervasyon', $form)->assertRedirect()->assertSessionHasNoErrors();
        $b = Booking::withoutTenantScope()->firstOrFail();
        $this->assertSame([BookingStatus::PENDING_APPROVAL, 'site', 'Ahmet Yılmaz', '+905321112233', 'Yılmaz Ltd.', null], [$b->status, $b->source, $b->customer_name, $b->customer_phone, $b->company_name, $b->company_id]);
        $this->assertNotNull($b->consented_at);
        $this->assertStringEndsWith('/rezervasyon/'.$b->uuid, $response->headers->get('Location'));
        $this->get('/rezervasyon/'.$b->uuid)->assertOk()->assertSee($b->reference)->assertSee('Onay bekliyor')->assertSee('Talebiniz alındı')->assertSee('noindex, nofollow');
        $this->get('/rezervasyon')->assertOk()->assertSee('<title>Toplantı odası rezervasyonu', false)->assertSee('index, follow');
        $this->get('/rezervasyon/00000000-0000-0000-0000-000000000000')->assertNotFound();

        // Bildirimler: WhatsApp sağlayıcısı GERÇEKTEN çağrıldı (Gateway → Meta), uygulama içi düştü, e-posta gönderildi; müşteri e-postası.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/1234567890/messages') && $req['to'] === '903326060999' && str_contains((string) $req['text']['body'], $b->reference) && str_contains((string) $req['text']['body'], 'Ahmet Yılmaz') && $req->hasHeader('Authorization'));
        $logs = NotificationLog::query()->where('entity_type', 'booking')->where('entity_id', $b->id)->get();
        $this->assertSame(['email', 'email', 'in_app', 'whatsapp'], $logs->pluck('channel')->sort()->values()->all());
        $this->assertSame('sent', $logs->firstWhere('channel', 'whatsapp')->status);
        $this->assertSame('wamid.TEST1', $logs->firstWhere('channel', 'whatsapp')->provider_message_id);
        $this->assertSame(1, $ops->unreadNotifications()->count());
        $this->assertStringNotContainsString('tok-test', json_encode($logs->toArray()) ?: '');
        $this->assertTrue($logs->contains(fn ($l) => $l->channel === 'email' && $l->recipient === 'ahmet@ornek.com'));

        // Uygunluk: talep odayı tutuyor (pending hold).
        $this->get('/rezervasyon?lokasyon='.$this->kadikoy->id.'&gun=2026-09-18')->assertOk()->assertSee('data-booking-slot-btn="14:00" disabled', false);

        // Yönetici: onay bekleyen sekmesi + zil + detay; onay → CONFIRMED, müşteriye e-posta, WhatsApp'a onay mesajı.
        $this->actingAs($ops)->get('/panel/rezervasyonlar?sekme=pending')->assertOk()->assertSee($b->reference)->assertSee('Ahmet Yılmaz')->assertSee('Onay bekliyor');
        $this->actingAs($ops)->get('/panel/bildirimler/gelen')->assertOk()->assertSee('Yeni randevu')->assertSee('Kaydı aç');
        $this->actingAs($ops)->get("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}")->assertOk()->assertSee('Onayla')->assertSee('+905321112233')->assertSee('Web sitesi')->assertSee('booking.created');
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}/onayla", ['note' => 'uygun'])->assertRedirect("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}")->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame([BookingStatus::CONFIRMED, $ops->id], [$b->status, (int) $b->approved_by]);
        $this->assertNull($b->expires_at);
        $this->assertTrue(NotificationLog::query()->where('entity_id', $b->id)->where('event', 'booking.confirmed')->where('channel', 'email')->where('recipient', 'ahmet@ornek.com')->where('status', 'sent')->exists());
        $this->get('/rezervasyon/'.$b->uuid)->assertOk()->assertSee('Onaylı')->assertSee('onaylandı');

        // Onaylı kayıt takvimi meşgul tutar; tekrar onay durum makinesince reddedilir.
        $this->from('/rezervasyon')->post('/rezervasyon', $form + ['email' => 'baska@ornek.com'])->assertSessionHasErrors('start');
        $this->actingAs($ops)->from("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}")->post("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}/onayla")->assertSessionHasErrors('booking');

        // Denetim izi tam: created → status_changed (talep → onay bekliyor) → status_changed (onay).
        $trail = AuditLog::query()->where('entity_type', 'booking')->where('entity_id', $b->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['booking.created', 'booking.status_changed', 'booking.status_changed'], $trail);
        $this->assertTrue(AuditLog::query()->where('action', 'booking.status_changed')->where('actor_id', $ops->id)->exists());

        // Check-in → tamamlandı (booking.manage); reddetme artık mümkün değil.
        Carbon::setTestNow('2026-09-18 14:05:00');
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}/giris")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(BookingStatus::CHECKED_IN, $b->fresh()->status);
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}/tamamla")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(BookingStatus::COMPLETED, $b->fresh()->status);
        $this->actingAs($ops)->get('/panel/rezervasyonlar?sekme=completed')->assertOk()->assertSee($b->reference);
        // Takvim: hafta görünümünde oda satırında rezervasyon; müşteri erişemez.
        $this->actingAs($ops)->get('/panel/rezervasyonlar/lokasyon/kadikoy/takvim?gun=2026-09-18')->assertOk()->assertSee('Toplantı 1')->assertSee('14:00–15:00')->assertSee('Yılmaz Ltd.');
        $this->actingAs($ops)->get('/panel/rezervasyonlar/lokasyon/kadikoy/takvim?gorunum=day&gun=2026-09-19')->assertOk()->assertDontSee('14:00–15:00');
        $this->actingAs($ops)->get('/panel/bildirimler?sekme=gunluk')->assertOk()->assertSee('wamid.TEST1')->assertDontSee('+903326060999');
    }

    #[Test]
    public function saglayici_arizasi_talebi_bozmaz_ve_red_gelmedi_sure_dolumu(): void
    {
        // WhatsApp kapalı → günlük 'atlandı'; talep yine kaydedilir (§19).
        $ops = $this->staff('operations_admin');
        NotificationRecipient::create(['name' => 'WA', 'channel' => 'whatsapp', 'address' => '+903326060999', 'group' => 'booking_managers', 'is_active' => true]);
        $form = ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '09:00', 'hours' => 1, 'name' => 'Ayşe', 'email' => 'ayse@ornek.com', 'phone' => '+905320000001', 'kvkk' => '1'];
        $this->post('/rezervasyon', $form)->assertRedirect()->assertSessionHasNoErrors();
        $b = Booking::withoutTenantScope()->firstOrFail();
        $this->assertSame('skipped', NotificationLog::query()->where('entity_id', $b->id)->where('channel', 'whatsapp')->firstOrFail()->status);

        // Sağlayıcı 500 döndürür → talep bozulmaz, kayıt failed (sync kuyruk), süper yöneticiye uyarı.
        config(['integrations.providers.whatsapp.enabled' => true, 'integrations.providers.whatsapp.secrets' => ['access_token' => 'tok', 'phone_number_id' => '99']]);
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['157.240.1.1']));
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400)]);
        $super = $this->staff('super_admin');
        $this->post('/rezervasyon', ['start' => '11:00'] + $form)->assertRedirect()->assertSessionHasNoErrors();
        $b2 = Booking::withoutTenantScope()->orderByDesc('id')->firstOrFail();
        $this->assertSame(BookingStatus::PENDING_APPROVAL, $b2->status);
        $wa = NotificationLog::query()->where('entity_id', $b2->id)->where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame(['failed', 'whatsapp:131047'], [$wa->status, $wa->error_code]);
        $this->assertSame(1, $super->unreadNotifications()->count());

        // Red: gerekçe zorunlu; müşteri sayfası gerekçeyi gösterir.
        $this->actingAs($ops)->from("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}")->post("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}/reddet", ['reason' => 'kısa'])->assertSessionHasErrors('reason');
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/kadikoy/{$b->id}/reddet", ['reason' => 'Oda bakımda'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(BookingStatus::REJECTED, $b->fresh()->status);
        $this->get('/rezervasyon/'.$b->uuid)->assertOk()->assertSee('Oda bakımda');
        // Reddedilen saat serbest.
        $this->post('/rezervasyon', $form + ['email' => 'c@ornek.com'])->assertSessionHasNoErrors();

        // Süre dolumu: 24 saat sonra zamanlayıcı bekleyen talebi EXPIRED yapar; saat serbest kalır.
        Carbon::setTestNow('2026-09-18 08:30:00');
        $this->artisan('booking:expire-requests')->assertSuccessful();
        $this->assertSame(BookingStatus::EXPIRED, $b2->fresh()->status);
        $this->assertSame('onay süresi doldu', $b2->fresh()->history->last()->reason);
        $this->autoConfirm(true);
        $this->post('/rezervasyon', ['date' => '2026-09-18', 'start' => '11:00'] + $form)->assertSessionHasNoErrors();
        $this->assertSame(BookingStatus::CONFIRMED, Booking::withoutTenantScope()->orderByDesc('id')->firstOrFail()->status);

        // Gelmedi: başlangıçtan önce işaretlenemez; sonra işaretlenir.
        $conf = Booking::withoutTenantScope()->orderByDesc('id')->firstOrFail();
        $this->actingAs($ops)->from('/')->post("/panel/rezervasyonlar/lokasyon/kadikoy/{$conf->id}/gelmedi")->assertSessionHasErrors('booking');
        Carbon::setTestNow('2026-09-18 11:20:00');
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/kadikoy/{$conf->id}/gelmedi", ['note' => 'aranmadı'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(BookingStatus::NO_SHOW, $conf->fresh()->status);
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

        $this->actingAs($betaOwner)->withContext($beta)->get("/panel/sirketler/{$acmeCo->id}/rezervasyonlar")->assertNotFound();
        $this->actingAs($betaOwner)->withContext($beta)->post("/panel/sirketler/{$acmeCo->id}/rezervasyonlar/{$booking->id}/iptal")->assertNotFound();
        $this->actingAs($betaOwner)->withContext($beta)->post("/panel/sirketler/{$betaCo->id}/rezervasyonlar/{$booking->id}/iptal")->assertNotFound();
        $this->actingAs($betaOwner)->withContext($beta)->get("/panel/sirketler/{$betaCo->id}/rezervasyonlar")->assertOk()->assertDontSee('Acme');
        $this->actingAs($betaOwner)->withContext($beta)->from('/')->post("/panel/sirketler/{$betaCo->id}/rezervasyonlar", ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '10:00', 'hours' => 1])->assertSessionHasErrors('start');
        $this->assertTrue($booking->fresh()->isActive());
        // Müşteri, personel ekranlarına giremez; sitedeki uuid sayfası kimlik gerektirmez ama tahmin edilemez.
        $this->actingAs($owner)->withContext($acme)->get('/panel/rezervasyonlar')->assertForbidden();
        $this->actingAs($owner)->withContext($acme)->get("/panel/rezervasyonlar/lokasyon/kadikoy/{$booking->id}")->assertForbidden();
    }

    #[Test]
    public function resepsiyon_masasi_lokasyon_kapsami_ve_operations_admin_jit_override(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $this->owner($acme, $acmeCo);
        $this->autoConfirm(true);

        $reception = User::factory()->create();
        $this->grantRole($reception, 'reception', ['location_id' => $this->kadikoy->id]);

        $this->actingAs($reception)->get("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}")->assertRedirect('/panel/hesap/guvenlik');
        $reception->forceFill(['two_factor_secret' => encrypt('x'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($reception)->get("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}?gun=2026-09-18")->assertOk()
            ->assertSee('Kadıköy masası')->assertSee('Masadan rezervasyon')->assertSee('Acme A.Ş.')->assertDontSee('Kural dışı');
        $this->actingAs($reception)->get("/panel/rezervasyonlar/lokasyon/{$this->levent->slug}")->assertForbidden();
        $this->actingAs($reception)->get('/panel/rezervasyonlar')->assertForbidden();
        $this->actingAs($reception)->get('/panel/hesap')->assertOk()->assertSee('Kadıköy masası');

        $this->actingAs($reception)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}", ['company_id' => $acmeCo->id, 'room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '09:00', 'hours' => 1])
            ->assertRedirect("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}?gun=2026-09-18")->assertSessionHasNoErrors();
        $booking = Booking::withoutTenantScope()->firstOrFail();
        $this->assertSame([(int) $reception->id, 'desk', BookingStatus::CONFIRMED], [(int) $booking->booked_by, $booking->source, $booking->status]);
        $this->actingAs($reception)->from('/')->post("/panel/rezervasyonlar/lokasyon/{$this->levent->slug}", ['company_id' => $acmeCo->id, 'room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '11:00', 'hours' => 1])->assertForbidden();
        $this->actingAs($reception)->from('/')->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}", ['company_id' => $acmeCo->id, 'room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '20:00', 'hours' => 1, 'override' => 1])->assertSessionHasErrors('start');
        // Resepsiyon: onay/iptal yok, check-in var (booking.manage,location).
        $this->actingAs($reception)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}/{$booking->id}/iptal", ['reason' => 'müşteri aradı'])->assertForbidden();
        $this->actingAs($reception)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}/{$booking->id}/onayla")->assertForbidden();
        $this->actingAs($reception)->put("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}/{$booking->id}/not", ['internal_note' => 'VIP müşteri'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('VIP müşteri', $booking->fresh()->internal_note);
        $this->actingAs($reception)->get("/panel/rezervasyonlar/lokasyon/{$this->levent->slug}/{$booking->id}")->assertForbidden();

        // operations_admin: genel liste + masa; JIT'siz iptal/kural dışı yok.
        $ops = $this->staff('operations_admin');
        $this->actingAs($ops)->get('/panel/rezervasyonlar')->assertOk()->assertSee('Acme A.Ş.')->assertSee('Kadıköy')->assertSee('Levent');
        $this->actingAs($ops)->get('/panel/rezervasyonlar?lokasyon='.$this->levent->id)->assertOk()->assertDontSee('Acme A.Ş.');
        $this->actingAs($ops)->get("/panel/rezervasyonlar/lokasyon/{$this->levent->slug}")->assertOk()->assertSee('Kural dışı erişim (JIT)');
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}/{$booking->id}/iptal", ['reason' => 'oda arızası'])->assertForbidden();
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}", ['company_id' => $acmeCo->id, 'room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '13:00', 'hours' => 1])->assertForbidden();

        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}/jit", ['reason' => 'gece etkinliği için oda açılacak', 'ttl_minutes' => 30])
            ->assertRedirect("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}")->assertSessionHasNoErrors();
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/{$this->levent->slug}/{$booking->id}/iptal", ['reason' => 'oda arızası'])->assertForbidden();
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}", ['company_id' => $acmeCo->id, 'room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '20:00', 'hours' => 1, 'override' => 1])->assertSessionHasNoErrors();
        $this->assertTrue(Booking::withoutTenantScope()->where('overridden', true)->exists());
        $this->actingAs($ops)->from('/')->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}", ['company_id' => $acmeCo->id, 'room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '09:00', 'hours' => 1, 'override' => 1])->assertSessionHasErrors('start');
        // Yeniden planlama (booking.manage): çakışma denetimi; iptal (JIT).
        $this->actingAs($ops)->from('/')->put("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}/{$booking->id}/planla", ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '20:00', 'hours' => 1])->assertSessionHasErrors('start');
        $this->actingAs($ops)->put("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}/{$booking->id}/planla", ['room_id' => $this->room->id, 'date' => '2026-09-18', 'start' => '15:00', 'hours' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['2026-09-18 15:00', 800], [$booking->fresh()->starts_at->format('Y-m-d H:i'), $booking->fresh()->total_amount]);
        $this->actingAs($ops)->post("/panel/rezervasyonlar/lokasyon/{$this->kadikoy->slug}/{$booking->id}/iptal", ['reason' => 'oda arızası'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(BookingStatus::CANCELLED, $booking->fresh()->status);
        $this->actingAs($this->staff('system_admin'))->get('/panel/denetim?tur=jit')->assertOk()->assertSee('booking.admin_override');
        $this->actingAs($this->staff('system_admin'))->get('/panel/denetim?tur=general')->assertOk()->assertSee('booking.status_changed')->assertSee('booking.rescheduled');
    }

    #[Test]
    public function odalar_geo_edit_ile_yonetilir_ve_vitrin_odalardan_beslenir(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin');
        $base = "/panel/geo/lokasyon/{$this->kadikoy->slug}/odalar";

        $this->actingAs($admin)->get("/panel/geo/lokasyon/{$this->kadikoy->slug}")->assertOk()->assertSee('Odalar');
        $this->actingAs($ops)->get($base)->assertOk()->assertSee('Toplantı 1')->assertSee('Yeni oda');

        $room = ['name' => 'Etkinlik', 'kind' => 'event', 'capacity' => 40, 'hourly_rate' => 1500, 'open_from' => '10:00', 'open_until' => '09:00', 'slot_minutes' => 60, 'max_hours' => 8, 'is_active' => 1];
        $this->actingAs($ops)->from($base)->post($base, $room)->assertSessionHasErrors('open_until');
        $this->actingAs($ops)->post($base, ['open_until' => '22:00'] + $room)->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertSame(2, Room::count());
        $this->assertTrue(AuditLog::query()->where('action', 'room.created')->where('actor_id', $ops->id)->exists());

        // Vitrin: gerçek odalar listelenir (fiyat, kapasite); teklif formunda toplantı odası seçeneği.
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('Etkinlik', $home);
        $this->assertStringContainsString('1.500 ₺/saat', $home);
        $this->assertStringContainsString('Toplantı odası', $home);

        $event = Room::where('name', 'Etkinlik')->firstOrFail();
        $this->actingAs($ops)->put("{$base}/{$event->id}", ['open_until' => '22:00', 'is_active' => 0] + $room)->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertFalse($event->fresh()->is_active);
        $this->assertSame(['Toplantı 1'], app(BookingService::class)->bookableRooms()->pluck('name')->all());
        $this->actingAs($ops)->put("/panel/geo/lokasyon/{$this->levent->slug}/odalar/{$event->id}", $room)->assertNotFound();

        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $acmeCo);
        $this->actingAs($owner)->withContext($acme)->post("/panel/sirketler/{$acmeCo->id}/rezervasyonlar", ['room_id' => $this->room->id, 'date' => '2026-09-20', 'start' => '10:00', 'hours' => 1])->assertSessionHasNoErrors();
        $this->actingAs($ops)->from($base)->delete("{$base}/{$this->room->id}")->assertSessionHasErrors('room');
        $this->actingAs($ops)->delete("{$base}/{$event->id}")->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertSame(1, Room::count());
        $this->actingAs($this->staff('finance_admin'))->get($base)->assertForbidden();

        // Oda yoksa vitrin bölümü basılmaz (uydurma kart yok).
        Room::query()->update(['is_active' => false]);
        $this->assertStringNotContainsString('id="toplanti"', $this->get('http://localhost/')->assertOk()->getContent());
    }
}
