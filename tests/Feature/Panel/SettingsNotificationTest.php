<?php

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Location;
use App\Models\NotificationRecipient;
use App\Models\NotificationRule;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Services\NotificationService;
use App\Services\SettingsService;
use Database\Seeders\NotificationRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ayar merkezi (§31–33: tanım kayıtlı, kapsam kalıtımı, doğrulama, audit) ve bildirim
 * merkezi (§16–18: kural matrisi, alıcılar DB'de, şablon, günlük, ilk kurulum komutu).
 */
class SettingsNotificationTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    #[Test]
    public function ayar_merkezi_kalitim_dogrulama_izin_ve_denetim(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // settings.view var, manage yok
        $finance = $this->staff('finance_admin'); // hiçbiri yok
        $konya = Location::create(['name' => 'Konya', 'slug' => 'konya']);
        $settings = app(SettingsService::class);

        $this->actingAs($finance)->get('/panel/ayarlar')->assertForbidden();
        $this->actingAs($ops)->get('/panel/ayarlar')->assertOk()->assertSee('Otomatik onay')->assertSee('Yalnız görüntüleme');
        $this->actingAs($ops)->put('/panel/ayarlar', ['booking__min_advance_hours' => 1])->assertForbidden();

        // Varsayılan (kod, teknik) → kurulum değeri → lokasyon üzerine yazma.
        $this->assertSame(2, $settings->int('booking.min_advance_hours'));
        $this->actingAs($admin)->put('/panel/ayarlar', ['booking__min_advance_hours' => 4, 'booking__auto_confirm' => 1, 'booking__reference_prefix' => 'OFV'])->assertRedirect('/panel/ayarlar')->assertSessionHasNoErrors();
        $this->assertSame([4, true, 'OFV'], [$settings->int('booking.min_advance_hours'), $settings->bool('booking.auto_confirm'), $settings->string('booking.reference_prefix')]);
        $this->assertSame(4, $settings->int('booking.min_advance_hours', ['location_id' => $konya->id]));

        $this->actingAs($admin)->put('/panel/ayarlar?lokasyon='.$konya->id, ['booking__min_advance_hours' => 0, 'booking__auto_confirm_inherit' => 1])->assertRedirect('/panel/ayarlar?lokasyon='.$konya->id)->assertSessionHasNoErrors();
        $this->assertSame(0, $settings->int('booking.min_advance_hours', ['location_id' => $konya->id]));
        $this->assertSame(4, $settings->int('booking.min_advance_hours')); // kurulum değeri değişmedi
        $this->assertTrue($settings->bool('booking.auto_confirm', ['location_id' => $konya->id])); // miras
        $this->actingAs($admin)->get('/panel/ayarlar?lokasyon='.$konya->id)->assertOk()->assertSee('Lokasyon: Konya')->assertSee('miras: 4');

        // Doğrulama kayıttan: aralık dışı ve biçim dışı reddedilir; tanımsız anahtar yazılmaz.
        $this->actingAs($admin)->from('/panel/ayarlar')->put('/panel/ayarlar', ['booking__max_advance_days' => 999])->assertSessionHasErrors('booking__max_advance_days');
        $this->actingAs($admin)->from('/panel/ayarlar')->put('/panel/ayarlar', ['booking__reference_prefix' => 'abc'])->assertSessionHasErrors('booking__reference_prefix');
        $this->actingAs($admin)->put('/panel/ayarlar', ['bilinmeyen__anahtar' => 'x'])->assertRedirect();
        $this->assertSame(0, Setting::query()->where('key', 'like', 'bilinmeyen%')->count());
        $this->actingAs($admin)->put('/panel/ayarlar?lokasyon=999', [])->assertNotFound();

        // Audit: her değişiklik önce/sonra ile.
        $this->assertTrue(AuditLog::query()->where('action', 'settings.changed')->where('actor_id', $admin->id)->exists());
        $log = AuditLog::query()->where('entity_type', 'setting:booking.min_advance_hours@installation')->orderBy('id')->firstOrFail();
        $this->assertSame([['value' => null], ['value' => 4]], [$log->before, $log->after]);
        $this->assertTrue(AuditLog::query()->where('entity_type', 'setting:booking.min_advance_hours@location#'.$konya->id)->exists());
    }

    #[Test]
    public function bildirim_merkezi_kurallar_alicilar_sablon_gunluk_ve_ilk_kurulum(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // notification.view
        $finance = $this->staff('finance_admin');
        $notifications = app(NotificationService::class);

        $this->actingAs($finance)->get('/panel/bildirimler')->assertForbidden();
        $this->actingAs($ops)->get('/panel/bildirimler')->assertOk()->assertSee('Yeni rezervasyon talebi')->assertDontSee('Kuralları kaydet');
        $this->actingAs($ops)->post('/panel/bildirimler/alicilar', ['name' => 'X', 'channel' => 'whatsapp', 'address' => '+905000000000', 'group' => 'booking_managers'])->assertForbidden();

        // İlk kurulum komutu: varsayılan kurallar + env'den WhatsApp alıcısı (kodda numara yok); idempotent.
        $this->assertSame(0, NotificationRule::count());
        config(['ofisvio.notifications.booking_whatsapp' => '+903326060999']);
        $this->artisan('ofisvio:bootstrap-notifications')->assertSuccessful();
        $this->artisan('ofisvio:bootstrap-notifications')->assertSuccessful();
        $this->assertGreaterThan(5, NotificationRule::count());
        $this->assertSame(1, NotificationRecipient::query()->where('channel', 'whatsapp')->count());
        $this->assertSame('+903326060999', NotificationRecipient::query()->firstOrFail()->address);
        config(['ofisvio.notifications.booking_whatsapp' => '0332 606 09 99']);
        $this->artisan('ofisvio:bootstrap-notifications')->assertFailed();
        $this->seed(NotificationRuleSeeder::class); // hiç kural yokken yazar; varsa dokunmaz
        $this->assertTrue(NotificationRule::query()->where('event', 'booking.requested')->where('channel', 'whatsapp')->where('recipient_group', 'booking_managers')->where('enabled', true)->exists());

        // Alıcı: E.164 doğrulama, e-posta, uygulama içi (kullanıcı zorunlu), müşteri grubu seçilemez; panelde maskeli.
        $this->actingAs($admin)->from('/panel/bildirimler')->post('/panel/bildirimler/alicilar', ['name' => 'Resepsiyon', 'channel' => 'whatsapp', 'address' => '0532', 'group' => 'booking_managers'])->assertSessionHasErrors('recipient');
        $this->actingAs($admin)->from('/panel/bildirimler')->post('/panel/bildirimler/alicilar', ['name' => 'Ops', 'channel' => 'in_app', 'group' => 'booking_managers'])->assertSessionHasErrors('recipient');
        $this->actingAs($admin)->from('/panel/bildirimler')->post('/panel/bildirimler/alicilar', ['name' => 'Müşteri', 'channel' => 'email', 'address' => 'x@y.z', 'group' => 'customer'])->assertSessionHasErrors('recipient');
        $this->actingAs($admin)->post('/panel/bildirimler/alicilar', ['name' => 'Ops', 'channel' => 'in_app', 'user_id' => $ops->id, 'group' => 'booking_managers', 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/panel/bildirimler/alicilar', ['name' => 'Muhasebe', 'channel' => 'email', 'address' => 'muhasebe@ofisvio.test', 'group' => 'finance', 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->get('/panel/bildirimler?sekme=alicilar')->assertOk()->assertSee('+903*******99')->assertSee('mu***@ofisvio.test')->assertDontSee('+903326060999');
        $email = NotificationRecipient::query()->where('channel', 'email')->firstOrFail();
        $this->actingAs($admin)->post("/panel/bildirimler/alicilar/{$email->id}/durum")->assertRedirect();
        $this->assertFalse($email->fresh()->is_active);
        $this->actingAs($admin)->delete("/panel/bildirimler/alicilar/{$email->id}")->assertRedirect();
        $this->assertNull($email->fresh());
        $this->assertSame(4, AuditLog::query()->where('entity_type', 'notification_recipient')->count()); // 2 ekleme + durum + silme

        // Kural matrisi: kapatılan kural bildirim üretmez; şablon panelden ezilir ve yer tutucular dolar.
        $this->actingAs($admin)->put('/panel/bildirimler/kurallar', ['rules' => ['booking.requested' => ['in_app' => ['booking_managers' => 1]]]])->assertRedirect();
        $this->assertFalse(NotificationRule::query()->where('event', 'booking.requested')->where('channel', 'whatsapp')->where('enabled', true)->exists());
        $this->actingAs($admin)->put('/panel/bildirimler/sablonlar', ['event' => 'booking.requested', 'channel' => 'in_app', 'locale' => 'tr', 'subject' => 'Randevu {{reference}}', 'body' => 'Yeni: {{customer_name}} · {{room}} · {{bilinmeyen}}!'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, NotificationTemplate::count());
        $logs = $notifications->dispatch('booking.requested', ['reference' => 'OV-1', 'customer_name' => 'Ali', 'room' => 'Toplantı 1']);
        $this->assertCount(1, $logs);
        $this->assertSame(['in_app', 'Randevu OV-1', 'Yeni: Ali · Toplantı 1 · !', 'sent'], [$logs[0]->channel, $logs[0]->subject, $logs[0]->body, $logs[0]->fresh()->status]);
        $this->assertSame(1, $ops->unreadNotifications()->count());
        $this->actingAs($ops)->get('/panel/bildirimler/gelen')->assertOk()->assertSee('Randevu OV-1');
        $this->actingAs($ops)->post('/panel/bildirimler/gelen/okundu')->assertRedirect();
        $this->assertSame(0, $ops->unreadNotifications()->count());
        // Şablon boş kaydedilince varsayılana döner; günlük sekmesi.
        $this->actingAs($admin)->put('/panel/bildirimler/sablonlar', ['event' => 'booking.requested', 'channel' => 'in_app', 'locale' => 'tr', 'body' => ''])->assertRedirect();
        $this->assertSame(0, NotificationTemplate::count());
        $this->actingAs($admin)->get('/panel/bildirimler?sekme=gunluk')->assertOk()->assertSee('Yeni rezervasyon talebi')->assertSee('Gönderildi')->assertSee('database');
        $this->actingAs($admin)->get('/panel/bildirimler?sekme=sablonlar&olay=booking.confirmed&kanal=whatsapp')->assertOk()->assertSee('Rezervasyon Onaylandı');
    }
}
