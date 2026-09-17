<?php

namespace Tests\Feature\Panel;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\LocationMedia;
use App\Models\Media;
use App\Models\Room;
use App\Models\Space;
use App\Models\SpaceAssignment;
use App\Models\Website;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 46 — Envanter ekranı yeniden tasarımı: tek ekrandan envanter (alan/oda) ekle-düzenle, hızlı tahsis
 * (üye + süre + demirbaş), tahsis değiştir/sonlandır, demirbaş yönetimi, sekme/durum süzgeçleri, kart bilgileri,
 * yetki ve lokasyon kapsamı.
 */
class InventoryScreenTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Location $kadikoy;

    private Location $ankara;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        Carbon::setTestNow('2026-09-18 10:00:00');
        $this->kadikoy = Location::create(['name' => 'Konya Merkez', 'slug' => 'konya-merkez', 'city' => 'Konya', 'region' => 'İç Anadolu', 'is_active' => true, 'is_published' => true]);
        $this->ankara = Location::create(['name' => 'Ankara', 'slug' => 'ankara', 'city' => 'Ankara', 'region' => 'İç Anadolu', 'is_active' => true, 'is_published' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function tek_ekrandan_envanter_ekle_tahsis_et_demirbas_bagla_degistir_ve_sonlandir(): void
    {
        $ops = $this->staff('operations_admin');
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $acmeCo);
        $stranger = $this->member($this->organization('Beta'));

        // 1) Envanter ekle (modal → alan rotası): kod, kat, durum; sekme/kart doğrulanır.
        $this->actingAs($ops)->post('/panel/alanlar/envanter/alan', ['_tab' => 'masalar', 'kind' => 'desk_fixed', 'location_id' => $this->kadikoy->id, 'name' => 'Masa A-104', 'code' => 'A-104', 'floor' => '1', 'zone' => 'Pencere', 'capacity' => 1, 'monthly_price' => '3500', 'status' => 'active', 'amenities' => 'Monitör, Kilitli dolap'])
            ->assertRedirect('/panel/alanlar?sekme=masalar')->assertSessionHasNoErrors();
        $desk = Space::query()->where('code', 'A-104')->firstOrFail();
        $this->assertSame([350000, 'Masa A-104', ['Monitör', 'Kilitli dolap']], [$desk->monthly_price, $desk->name, $desk->amenityList()]);
        // Aynı kod ikinci kez reddedilir; bakım durumu tarih ister.
        $this->actingAs($ops)->from('/panel/alanlar')->post('/panel/alanlar/envanter/alan', ['kind' => 'office', 'location_id' => $this->kadikoy->id, 'name' => 'Ofis 1', 'code' => 'A-104', 'status' => 'active'])->assertSessionHasErrors('name');
        $this->actingAs($ops)->from('/panel/alanlar')->post('/panel/alanlar/envanter/alan', ['kind' => 'office', 'location_id' => $this->kadikoy->id, 'name' => 'Ofis 1', 'status' => 'maintenance'])->assertSessionHasErrors('maintenance_until');
        $this->actingAs($ops)->post('/panel/alanlar/envanter/alan', ['kind' => 'office', 'location_id' => $this->ankara->id, 'name' => 'Ofis 1', 'code' => 'OF-1', 'capacity' => 4, 'status' => 'maintenance', 'maintenance_until' => '2026-09-30', 'maintenance_note' => 'Boya'])->assertSessionHasNoErrors();
        $office = Space::query()->where('code', 'OF-1')->firstOrFail();
        $this->assertSame('maintenance', $office->inventoryStatus());

        // Oda ekleme aynı modal, oda rotası (geo.edit).
        $this->actingAs($ops)->post('/panel/alanlar/envanter/oda', ['kind' => 'meeting', 'location_id' => $this->kadikoy->id, 'name' => 'Toplantı 1', 'code' => 'T-1', 'capacity' => 6, 'hourly_rate' => '400', 'open_from' => '09:00', 'open_until' => '18:00', 'slot_minutes' => 60, 'max_hours' => 4, 'is_active' => 1])->assertSessionHasNoErrors();
        $room = Room::query()->where('code', 'T-1')->firstOrFail();

        // 2) Demirbaş ekle: alana yerleşik + serbest; kod tekil; yabancı alan reddedilir.
        $this->actingAs($ops)->post('/panel/alanlar/demirbas', ['location_id' => $this->kadikoy->id, 'space_id' => $desk->id, 'name' => 'Ofis koltuğu', 'code' => 'DMB-1', 'category' => 'furniture', 'status' => 'available'])->assertRedirect('/panel/alanlar?sekme=demirbas')->assertSessionHasNoErrors();
        $this->actingAs($ops)->post('/panel/alanlar/demirbas', ['location_id' => $this->kadikoy->id, 'name' => '27" Monitör', 'code' => 'DMB-2', 'category' => 'electronics', 'serial' => 'SN-777', 'status' => 'available'])->assertSessionHasNoErrors();
        $this->actingAs($ops)->post('/panel/alanlar/demirbas', ['location_id' => $this->ankara->id, 'name' => 'Ankara dolabı', 'code' => 'DMB-3', 'category' => 'furniture', 'status' => 'available'])->assertSessionHasNoErrors();
        $this->actingAs($ops)->from('/panel/alanlar')->post('/panel/alanlar/demirbas', ['location_id' => $this->kadikoy->id, 'name' => 'Kopya', 'code' => 'DMB-1', 'category' => 'other', 'status' => 'available'])->assertSessionHasErrors('name');
        $this->actingAs($ops)->from('/panel/alanlar')->post('/panel/alanlar/demirbas', ['location_id' => $this->kadikoy->id, 'space_id' => $office->id, 'name' => 'Yanlış alan', 'category' => 'other', 'status' => 'available'])->assertSessionHasErrors('name');
        $chair = Asset::query()->where('code', 'DMB-1')->firstOrFail();
        $monitor = Asset::query()->where('code', 'DMB-2')->firstOrFail();
        $ankaraAsset = Asset::query()->where('code', 'DMB-3')->firstOrFail();

        // Kart: durum, kapasite, demirbaş sayısı, "Tahsis et" düğmesi; müsait süzgeci.
        $html = $this->actingAs($ops)->get('/panel/alanlar')->assertOk()->getContent();
        $this->assertStringContainsString('Masa A-104', $html);
        $this->assertStringContainsString('<span class="code">A-104</span>', $html);
        $this->assertStringContainsString('Müsait', $html);
        $this->assertStringContainsString('Tahsis et', $html);
        $this->assertStringContainsString('Bakımda', $html); // Ankara ofisi
        $this->assertStringContainsString('Toplantı 1', $html);
        $this->actingAs($ops)->get('/panel/alanlar?sekme=masalar&durum=bakimda')->assertOk()->assertDontSee('<span class="code">A-104</span>', false)->assertSee('Bu süzgeçte envanter yok');
        $this->actingAs($ops)->get('/panel/alanlar?sekme=ofisler&durum=bakimda')->assertOk()->assertSee('<span class="code">OF-1</span>', false);
        $this->actingAs($ops)->get('/panel/alanlar?q=a-104')->assertOk()->assertSee('<span class="code">A-104</span>', false)->assertDontSee('<span class="code">OF-1</span>', false);

        // 3) Hızlı tahsis: üye + süre + demirbaş; yabancı lokasyonun demirbaşı reddedilir; şirket dışı üye reddedilir.
        $this->actingAs($ops)->from('/panel/alanlar')->post('/panel/alanlar/tahsis', ['space_id' => $desk->id, 'company_id' => $acmeCo->id, 'user_id' => $stranger->id, 'starts_on' => '2026-09-18'])->assertSessionHasErrors('user_id');
        $this->actingAs($ops)->from('/panel/alanlar')->post('/panel/alanlar/tahsis', ['space_id' => $desk->id, 'company_id' => $acmeCo->id, 'starts_on' => '2026-09-18', 'asset_ids' => [$ankaraAsset->id]])->assertSessionHasErrors('company_id');
        $this->assertSame(0, SpaceAssignment::withoutTenantScope()->count());
        $this->actingAs($ops)->post('/panel/alanlar/tahsis', ['space_id' => $desk->id, 'company_id' => $acmeCo->id, 'user_id' => $owner->id, 'starts_on' => '2026-09-01', 'ends_on' => '2027-09-01', 'asset_ids' => [$chair->id, $monitor->id], 'note' => 'Yıllık'])
            ->assertRedirect('/panel/alanlar?sekme=tahsisler')->assertSessionHasNoErrors();
        $assignment = SpaceAssignment::withoutTenantScope()->firstOrFail();
        $this->assertSame(['assigned', $assignment->id, $desk->id], [$chair->fresh()->status, (int) $chair->fresh()->space_assignment_id, (int) $monitor->fresh()->space_id]);
        $this->assertTrue(AuditLog::query()->where('action', 'asset.assigned')->where('entity_id', $monitor->id)->exists());

        // Kart tek bakışta: TAHSİSLİ, üye, tarih aralığı, demirbaş 2.
        $html = $this->actingAs($ops)->get('/panel/alanlar?sekme=masalar')->assertOk()->getContent();
        $this->assertStringContainsString('Tahsisli', $html);
        $this->assertStringContainsString($owner->name, $html);
        $this->assertStringContainsString('01.09.2026 → 01.09.2027', $html);
        $this->assertStringContainsString('<dt>Demirbaş</dt><dd>2</dd>', $html);
        $this->assertStringContainsString('Tahsisi değiştir', $html);
        $this->actingAs($ops)->get('/panel/alanlar?sekme=masalar&durum=tahsisli')->assertOk()->assertSee('<span class="code">A-104</span>', false);
        $this->actingAs($ops)->get('/panel/alanlar?sekme=masalar&durum=musait')->assertOk()->assertDontSee('<span class="code">A-104</span>', false);
        $this->actingAs($ops)->get('/panel/alanlar?sekme=tahsisler')->assertOk()->assertSee('Acme A.Ş.')->assertSee('Sonlandır')->assertSee('Değiştir');
        $this->actingAs($ops)->get('/panel/alanlar?sekme=demirbas')->assertOk()->assertSee('DMB-1')->assertSee('Tahsisli')->assertSee('SN-777');
        // Tahsisli demirbaş silinemez.
        $this->actingAs($ops)->from('/panel/alanlar?sekme=demirbas')->delete("/panel/alanlar/demirbas/{$chair->id}")->assertSessionHasErrors('asset');

        // 4) Tahsisi değiştir: bitiş uzar, monitör tahsisten çıkar (serbest), koltuk kalır.
        $this->actingAs($ops)->put("/panel/alanlar/tahsis/{$assignment->id}", ['ends_on' => '2027-12-31', 'user_id' => $owner->id, 'asset_ids' => [$chair->id], 'note' => 'Uzatıldı'])->assertRedirect('/panel/alanlar?sekme=tahsisler')->assertSessionHasNoErrors();
        $this->assertSame(['2027-12-31', 'available', 'assigned'], [$assignment->fresh()->ends_on->toDateString(), $monitor->fresh()->status, $chair->fresh()->status]);
        $this->assertTrue(AuditLog::query()->where('action', 'space.assignment_updated')->where('entity_id', $assignment->id)->exists());
        $this->actingAs($ops)->from('/panel/alanlar')->put("/panel/alanlar/tahsis/{$assignment->id}", ['ends_on' => '2026-01-01'])->assertSessionHasErrors('ends_on');

        // 5) Sonlandır: tahsis biter, koltuk serbest, kart yeniden MÜSAİT.
        $this->actingAs($ops)->post("/panel/alanlar/tahsis/{$assignment->id}/bitir")->assertRedirect('/panel/alanlar?sekme=tahsisler')->assertSessionHasNoErrors();
        $this->assertSame(['ended', 'available'], [$assignment->fresh()->status, $chair->fresh()->status]);
        $this->actingAs($ops)->get('/panel/alanlar?sekme=masalar&durum=musait')->assertOk()->assertSee('<span class="code">A-104</span>', false);

        // 6) Düzenle: durum pasif → kart; aktif tahsisi olmayan alan başka lokasyona taşınır; oda düzenleme.
        $this->actingAs($ops)->put("/panel/alanlar/envanter/alan/{$desk->id}", ['kind' => 'desk_fixed', 'location_id' => $this->ankara->id, 'name' => 'Masa A-104', 'code' => 'A-104', 'capacity' => 1, 'monthly_price' => '3600', 'status' => 'inactive'])->assertRedirect('/panel/alanlar')->assertSessionHasNoErrors();
        $this->assertSame([$this->ankara->id, false, 360000], [(int) $desk->fresh()->location_id, $desk->fresh()->is_active, $desk->fresh()->monthly_price]);
        $this->actingAs($ops)->get('/panel/alanlar?durum=pasif')->assertOk()->assertSee('<span class="code">A-104</span>', false);
        $this->actingAs($ops)->put("/panel/alanlar/envanter/oda/{$room->id}", ['kind' => 'focus', 'location_id' => $this->kadikoy->id, 'name' => 'Odak 1', 'code' => 'T-1', 'capacity' => 2, 'hourly_rate' => '150', 'open_from' => '09:00', 'open_until' => '18:00', 'slot_minutes' => 30, 'max_hours' => 4, 'is_active' => 1, 'amenities' => 'Ses yalıtımı'])->assertSessionHasNoErrors();
        $this->assertSame(['focus', 'Odak 1', ['Ses yalıtımı']], [$room->fresh()->kind, $room->fresh()->name, $room->fresh()->amenityList()]);

        // Demirbaş düzenle (kod/kategori) ve sil.
        $this->actingAs($ops)->put("/panel/alanlar/demirbas/{$monitor->id}", ['location_id' => $this->kadikoy->id, 'name' => '27" Monitör', 'code' => 'DMB-2B', 'category' => 'it', 'status' => 'maintenance'])->assertSessionHasNoErrors();
        $this->assertSame(['DMB-2B', 'it', 'maintenance'], [$monitor->fresh()->code, $monitor->fresh()->category, $monitor->fresh()->status]);
        $this->actingAs($ops)->delete("/panel/alanlar/demirbas/{$monitor->id}")->assertRedirect('/panel/alanlar?sekme=demirbas')->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('assets', ['id' => $monitor->id]);
    }

    #[Test]
    public function yetki_ve_lokasyon_kapsami(): void
    {
        $desk = Space::create(['location_id' => $this->kadikoy->id, 'kind' => 'desk_fixed', 'name' => 'K-1', 'capacity' => 1, 'monthly_price' => 100000, 'is_active' => true]);
        $ankaraDesk = Space::create(['location_id' => $this->ankara->id, 'kind' => 'desk_fixed', 'name' => 'ANK-1', 'capacity' => 1, 'monthly_price' => 100000, 'is_active' => true]);
        $asset = Asset::create(['location_id' => $this->ankara->id, 'name' => 'Ankara koltuğu', 'category' => 'furniture', 'status' => 'available']);
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $owner = $this->owner($acme, $acmeCo);

        // Finans: space.view (global) görür; space.manage yok → yazma 403, düğme yok.
        $finance = $this->staff('finance_admin');
        $this->actingAs($finance)->get('/panel/alanlar')->assertOk()->assertSee('K-1')->assertDontSee('data-modal-open="#modal-assign"', false);
        $this->actingAs($finance)->post('/panel/alanlar/envanter/alan', ['kind' => 'desk_fixed', 'location_id' => $this->kadikoy->id, 'name' => 'X', 'status' => 'active'])->assertForbidden();
        $this->actingAs($finance)->post('/panel/alanlar/tahsis', ['space_id' => $desk->id, 'company_id' => $acmeCo->id, 'starts_on' => '2026-09-18'])->assertForbidden();
        $this->actingAs($finance)->post('/panel/alanlar/demirbas', ['location_id' => $this->kadikoy->id, 'name' => 'X', 'category' => 'other', 'status' => 'available'])->assertForbidden();
        // Müşteri sahibi panelin bu ekranına giremez.
        $this->actingAs($owner)->withContext($acme)->get('/panel/alanlar')->assertForbidden();

        // Lokasyon yöneticisi (Konya): kendi lokasyonuna yazar; Ankara'ya envanter/demirbaş/tahsis 404 (varlık sızmaz).
        $manager = $this->staff('location_manager');
        DB::table('user_roles')->where('user_id', $manager->id)->update(['location_id' => $this->kadikoy->id]);
        $this->actingAs($manager)->get('/panel/alanlar')->assertOk()->assertSee('<div class="inv-card__title"><a href="'.route('panel.spaces.show', $desk->id).'">K-1</a>', false)->assertDontSee('>ANK-1</a>', false)->assertSee('+ Envanter ekle');
        $this->actingAs($manager)->post('/panel/alanlar/envanter/alan', ['kind' => 'desk_flex', 'location_id' => $this->kadikoy->id, 'name' => 'Esnek', 'capacity' => 3, 'status' => 'active'])->assertRedirect('/panel/alanlar')->assertSessionHasNoErrors();
        $this->actingAs($manager)->post('/panel/alanlar/envanter/alan', ['kind' => 'desk_flex', 'location_id' => $this->ankara->id, 'name' => 'Sızma', 'capacity' => 3, 'status' => 'active'])->assertNotFound();
        $this->actingAs($manager)->put("/panel/alanlar/envanter/alan/{$ankaraDesk->id}", ['kind' => 'desk_fixed', 'location_id' => $this->ankara->id, 'name' => 'ANK-1', 'status' => 'inactive'])->assertNotFound();
        $this->actingAs($manager)->post('/panel/alanlar/tahsis', ['space_id' => $ankaraDesk->id, 'company_id' => $acmeCo->id, 'starts_on' => '2026-09-18'])->assertNotFound();
        $this->actingAs($manager)->put("/panel/alanlar/demirbas/{$asset->id}", ['location_id' => $this->ankara->id, 'name' => 'X', 'category' => 'other', 'status' => 'available'])->assertNotFound();
        $this->actingAs($manager)->post('/panel/alanlar/envanter/oda', ['kind' => 'meeting', 'location_id' => $this->kadikoy->id, 'name' => 'Oda', 'capacity' => 4, 'hourly_rate' => '100', 'open_from' => '09:00', 'open_until' => '18:00', 'slot_minutes' => 60, 'max_hours' => 4])->assertForbidden(); // geo.edit yok
        // Kendi lokasyonunda tahsis + lokasyon dışı demirbaş bağlanamaz (aynı lokasyon kuralı).
        $this->actingAs($manager)->from('/panel/alanlar')->post('/panel/alanlar/tahsis', ['space_id' => $desk->id, 'company_id' => $acmeCo->id, 'starts_on' => '2026-09-18', 'asset_ids' => [$asset->id]])->assertSessionHasErrors('company_id');
        $this->actingAs($manager)->post('/panel/alanlar/tahsis', ['space_id' => $desk->id, 'company_id' => $acmeCo->id, 'starts_on' => '2026-09-18'])->assertRedirect('/panel/alanlar?sekme=tahsisler')->assertSessionHasNoErrors();
        $this->assertSame(1, SpaceAssignment::withoutTenantScope()->count());

        // Kapak görseli: yalnız lokasyon galerisinden (modal'dan gelen yabancı id reddedilir).
        $site = Website::query()->default()->firstOrFail();
        $media = Media::create(['website_id' => $site->id, 'uploaded_by' => $manager->id, 'disk' => 'public', 'path' => 'media/x.jpg', 'original_name' => 'x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 100, 'width' => 800, 'height' => 600, 'checksum_sha256' => str_repeat('a', 64), 'status' => Media::STATUS_APPROVED]);
        LocationMedia::create(['location_id' => $this->ankara->id, 'media_id' => $media->id, 'category' => 'gallery', 'sort_order' => 1]);
        $this->actingAs($manager)->from('/panel/alanlar')->put("/panel/alanlar/envanter/alan/{$desk->id}", ['kind' => 'desk_fixed', 'location_id' => $this->kadikoy->id, 'name' => 'K-1', 'status' => 'active', 'cover_media_id' => $media->id])->assertSessionHasErrors('name');
    }
}
