<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Location;
use App\Models\Room;
use App\Models\SiteBlock;
use App\Models\Website;
use App\Services\ContentCache;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 10 — Vitrin blokları: config varsayılanı -> CMS kaydı -> vitrin;
 * satır doğrulama; boş metin = varsayılana dönüş; yetki content.publish.
 */
class SiteBlocksTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(LocationSeeder::class);
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
    }

    #[Test]
    public function blok_kaydi_config_varsayilanini_ezer_ve_vitrine_hemen_yansir(): void
    {
        $admin = $this->staff('system_admin');

        // Varsayılan: seed'lenmiş veritabanı kaydından (config'te ticari içerik yok).
        $seed = json_decode((string) file_get_contents(database_path('seeders/data/site_blocks.json')), true);
        $this->assertArrayNotHasKey('solutions', config('ofisvio'));
        $this->assertArrayNotHasKey('pricing_note', config('ofisvio'));
        $this->get('/')->assertOk()->assertSee('Sanal Ofis')->assertSee($seed['blocks']['pricing_note']);
        $this->actingAs($admin)->get('/panel/icerik')->assertOk()->assertSee('/panel/icerik/bloklar', false);
        $this->actingAs($admin)->get('/panel/icerik/bloklar')->assertOk()->assertSee('CMS kaydı')->assertSee('Sanal Ofis | Prestijli');

        $this->actingAs($admin)->put('/panel/icerik/bloklar/solutions', ['text' => "Sanal Ofis Plus | Tescil + çağrı + kargo | ₺990/ay'dan | evet\nGünlük Masa | Sözleşmesiz | ₺450/gün'den | hayır"])
            ->assertRedirect('/panel/icerik/bloklar')->assertSessionHasNoErrors();
        $this->actingAs($admin)->put('/panel/icerik/bloklar/pricing_note', ['text' => 'Fiyatlar KDV dahildir.'])->assertRedirect();
        $this->actingAs($admin)->put('/panel/icerik/bloklar/footer_columns', ['text' => 'Kurumsal | Hakkımızda, İletişim'])->assertRedirect();

        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('Sanal Ofis Plus', $home);
        $this->assertStringContainsString('Günlük Masa', $home);
        $this->assertStringNotContainsString('Hazır Ofis</h3>', $home, 'Seed listesi tamamen ezildi.');
        $this->assertStringContainsString('Fiyatlar KDV dahildir.', $home);
        $this->assertStringContainsString('amiral ürün', $home);
        $this->assertStringNotContainsString('>Kariyer<', $home, 'Footer sütunları ezildi.');
        $this->assertStringContainsString('>Hakkımızda<', $home);

        $this->actingAs($admin)->get('/panel/icerik/bloklar')->assertOk()->assertSee('CMS kaydı')->assertSee('Sanal Ofis Plus | Tescil + çağrı + kargo | ₺990/ay&#039;dan | evet', false);

        // Boş metin: kayıt silinir, bölüm vitrinden kalkar (kodda ticari varsayılan yok); seed yeniden getirir.
        $this->actingAs($admin)->put('/panel/icerik/bloklar/solutions', ['text' => ''])->assertRedirect();
        $this->assertSame(0, SiteBlock::where('key', 'solutions')->count());
        $this->get('http://localhost/')->assertOk()->assertDontSee('id="cozumler"', false)->assertDontSee('Sanal Ofis Plus');
        $this->seed(SiteBlockSeeder::class);
        app(ContentCache::class)->invalidate(Website::query()->default()->firstOrFail());
        $this->get('http://localhost/')->assertOk()->assertSee('Hazır Ofis');
    }

    #[Test]
    public function ana_sayfa_metinleri_ve_site_ayarlari_vitrine_yansir(): void
    {
        $admin = $this->staff('system_admin');
        $default = Website::query()->default()->firstOrFail();

        // Toplantı bölümü yalnız rezervasyona açık gerçek oda varsa basılır (booking engine).
        Room::create(['location_id' => Location::published()->firstOrFail()->id, 'name' => 'Toplantı A', 'kind' => 'meeting', 'capacity' => 4, 'hourly_rate' => 300, 'open_from' => '09:00', 'open_until' => '18:00', 'slot_minutes' => 60, 'max_hours' => 4]);
        $seedPhone = json_decode((string) file_get_contents(database_path('seeders/data/site_blocks.json')), true)['website']['contact_phone'];
        $this->get('/')->assertOk()->assertSee('Şirketinizin adresi')->assertSee($seedPhone);
        $this->actingAs($admin)->get('/panel/icerik/bloklar')->assertOk()->assertSee('Hero başlık (1. satır)');

        // Metinler: değişen saklanır, varsayılanla aynı olan saklanmaz.
        $this->actingAs($admin)->put('/panel/icerik/bloklar/metinler', [
            'hero_title' => 'Şirketinizin yeni adresi', 'hero_accent' => 'yarın', 'hero_title_after' => 'hazır.',
            'pricing_title' => config('ofisvio.texts.pricing_title'), 'meeting_lede' => 'Kısa toplantı açıklaması.',
        ])->assertRedirect('/panel/icerik/bloklar')->assertSessionHasNoErrors();
        $stored = SiteBlock::where('key', 'texts')->firstOrFail()->data;
        $this->assertSame(['hero_title', 'hero_accent', 'hero_title_after', 'meeting_lede'], array_keys($stored));

        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('Şirketinizin yeni adresi', $home);
        $this->assertStringContainsString('<span class="serif-accent">yarın</span> hazır.', $home);
        $this->assertStringContainsString('Kısa toplantı açıklaması.', $home);
        $this->assertStringContainsString(config('ofisvio.texts.solutions_title'), $home); // dokunulmayan alan varsayılan

        // Site genel ayarları (website.manage): telefon/e-posta/slogan/adres; boş = gösterilmez.
        $this->actingAs($admin)->get("/panel/websiteler/{$default->id}/duzenle")->assertOk()->assertSee('Site genel ayarları');
        $this->actingAs($admin)->put("/panel/websiteler/{$default->id}/ayarlar", ['contact_phone' => '0212 555 00 00', 'contact_email' => 'info@ofisvio.com', 'tagline' => 'Yeni slogan.', 'address' => 'Levent, İstanbul'])
            ->assertRedirect("/panel/websiteler/{$default->id}/duzenle")->assertSessionHasNoErrors();
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('0212 555 00 00', $home);
        $this->assertStringContainsString('href="tel:+902125550000"', $home);
        $this->assertStringContainsString('info@ofisvio.com', $home);
        $this->assertStringContainsString('Yeni slogan.', $home);
        $this->assertStringContainsString('Levent, İstanbul', $home);
        $this->assertStringNotContainsString($seedPhone, $home);

        $this->actingAs($admin)->from("/panel/websiteler/{$default->id}/duzenle")->put("/panel/websiteler/{$default->id}/ayarlar", ['contact_email' => 'bozuk'])->assertSessionHasErrors('contact_email');
        $this->actingAs($admin)->put("/panel/websiteler/{$default->id}/ayarlar", [])->assertRedirect();
        $this->assertNull($default->fresh()->contact_phone);
        $this->get('http://localhost/')->assertOk()->assertDontSee('tel:+90');
    }

    #[Test]
    public function whatsapp_calisma_saatleri_menu_ve_cta_metinleri_panelden_yonetilir(): void
    {
        $admin = $this->staff('system_admin');
        $default = Website::query()->default()->firstOrFail();

        // Ayar yokken: WhatsApp düğmesi basılmaz, KVKK bağlantısı ölü "#" değildir.
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringNotContainsString('wa.me', $home);
        $this->assertStringNotContainsString('href="#"', $home);

        // WhatsApp + saatler site ayarı; E.164 dışı reddedilir.
        $this->actingAs($admin)->from("/panel/websiteler/{$default->id}/duzenle")->put("/panel/websiteler/{$default->id}/ayarlar", ['whatsapp_number' => '0532 000 00 00'])->assertSessionHasErrors('whatsapp_number');
        $this->actingAs($admin)->put("/panel/websiteler/{$default->id}/ayarlar", ['whatsapp_number' => '+905320000000', 'business_hours' => "Pzt–Cum 08:30–19:00\n\nCmt 09:00–14:00"])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['Pzt–Cum 08:30–19:00', 'Cmt 09:00–14:00'], $default->fresh()->business_hours);

        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('https://wa.me/905320000000?text='.rawurlencode(config('ofisvio.texts.whatsapp_message')), $home);
        $this->assertStringContainsString('class="whatsapp-fab"', $home);
        $this->assertStringContainsString('Cmt 09:00–14:00', $home);

        // Menü etiketleri, CTA'lar, teklif vaatleri ve WhatsApp mesajı metin bloğundan; boş vaat satırı gizlenir.
        $this->actingAs($admin)->get('/panel/icerik/bloklar')->assertOk()->assertSee('Menü: Çözümler')->assertSee('WhatsApp ön yazılı mesaj');
        $this->actingAs($admin)->put('/panel/icerik/bloklar/metinler', [
            'nav_solutions' => 'Hizmetler', 'cta_header' => 'Fiyat iste', 'cta_hero' => 'Müsaitlik', 'lead_title' => 'Bize yazın',
            'lead_claim_2' => '', 'whatsapp_message' => 'Selam Ofisvio',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        foreach (['>Hizmetler<', '>Fiyat iste<', '>Müsaitlik<', 'Bize yazın', 'wa.me/905320000000?text=Selam%20Ofisvio'] as $needle) {
            $this->assertStringContainsString($needle, $home);
        }
        $this->assertStringNotContainsString(config('ofisvio.texts.lead_claim_2'), $home);
        $this->assertStringNotContainsString('Teklif Al<', $home);
        $this->assertStringContainsString(config('ofisvio.texts.lead_claim_1'), $home);

        // KVKK bağlantısı yayındaki aydınlatma sayfasına gider.
        $page = Content::create(['website_id' => $default->id, 'kind' => 'page', 'title' => 'KVKK Aydınlatma Metni', 'slug' => 'kvkk-aydinlatma', 'body' => 'Metin.']);
        $page->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()])->save();
        app(ContentCache::class)->invalidate($default);
        $this->assertStringContainsString('href="/kvkk-aydinlatma"', $this->get('http://localhost/')->assertOk()->getContent());

        // Duyuru şeridi (global bileşen): metin + bağlantı + bitiş; süresi geçince kaybolur; bozuk bağlantı reddedilir.
        $this->actingAs($admin)->from("/panel/websiteler/{$default->id}/duzenle")->put("/panel/websiteler/{$default->id}/ayarlar", ['announcement_text' => 'Yeni şube açıldı', 'announcement_href' => 'javascript:alert(1)'])->assertSessionHasErrors('announcement_href');
        $this->actingAs($admin)->put("/panel/websiteler/{$default->id}/ayarlar", ['announcement_text' => 'Yeni şube açıldı', 'announcement_href' => '/lokasyonlar', 'announcement_until' => now()->addDay()->format('Y-m-d\TH:i')])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringContainsString('href="/lokasyonlar" style="color:inherit;font-weight:600">Yeni şube açıldı', $this->get('http://localhost/')->assertOk()->getContent());
        $this->travel(2)->days();
        $this->assertStringNotContainsString('Yeni şube açıldı', $this->get('http://localhost/')->assertOk()->getContent());
        $this->travelBack();

        // Müşteri sitesi: kendi WhatsApp'ı; Ofisvio'nunki sızmaz.
        $acme = $this->organization('Acme');
        $site = Website::create(['organization_id' => $acme->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example', 'whatsapp_number' => '+905550001122', 'business_hours' => ['Her gün 09–18']]);
        $tenantHome = $this->get('http://acme.example/')->assertOk()->getContent();
        $this->assertStringContainsString('wa.me/905550001122', $tenantHome);
        $this->assertStringContainsString('Her gün 09–18', $tenantHome);
        $this->assertStringNotContainsString('905320000000', $tenantHome);
    }

    #[Test]
    public function bozuk_satir_reddedilir_ve_yetki_content_publish(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // content.edit var, publish yok

        $this->actingAs($admin)->from('/panel/icerik/bloklar')->put('/panel/icerik/bloklar/solutions', ['text' => 'Eksik | alan'])->assertSessionHasErrors('solutions');
        $this->actingAs($admin)->from('/panel/icerik/bloklar')->put('/panel/icerik/bloklar/plan_rows', ['text' => 'Tek hücre'])->assertSessionHasErrors('plan_rows');
        $this->actingAs($admin)->from('/panel/icerik/bloklar')->put('/panel/icerik/bloklar/bilinmeyen', ['text' => 'x | y'])->assertSessionHasErrors('bilinmeyen');
        $this->assertSame(6, SiteBlock::count(), 'Bozuk satırlar seed kayıtlarını değiştirmedi.');

        $this->actingAs($admin)->put('/panel/icerik/bloklar/plan_rows', ['text' => 'Şirket tescil adresi | Dahil | Opsiyonel | Dahil | —'])->assertRedirect();
        $this->assertSame(['Dahil', 'Opsiyonel', 'Dahil', '—'], SiteBlock::where('key', 'plan_rows')->firstOrFail()->data[0]['cells']);

        $this->actingAs($ops)->get('/panel/icerik/bloklar')->assertForbidden();
        $this->actingAs($ops)->put('/panel/icerik/bloklar/solutions', ['text' => 'A | B | C | evet'])->assertForbidden();

        // Müşteri sitesi bloklardan etkilenmez (kendi ana sayfası).
        $acme = $this->organization('Acme');
        Website::create(['organization_id' => $acme->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
        $this->get('http://acme.example/')->assertOk()->assertDontSee('Şirket tescil adresi');
    }
}
