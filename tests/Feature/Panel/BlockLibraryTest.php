<?php

namespace Tests\Feature\Panel;

use App\Models\SiteBlock;
use App\Models\SiteBlockPreset;
use App\Models\SiteSection;
use App\Models\Website;
use App\Services\SiteBuilderService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 50 — Blok kütüphanesi (/panel/icerik/bloklar): düzenleme yok, kayıtlı şablonlar + hazır bileşenler; kategori,
 * global/normal, kullanım, önizleme, kopya, ad/kategori, silme, editöre açma. Global blok: bağlı tüm kullanımlar
 * (yayın dahil) birlikte güncellenir. Vitrin veri listeleri editörde ilgili bölümden (taslak → yayın).
 */
class BlockLibraryTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(LocationSeeder::class);
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
        $this->site = Website::query()->default()->firstOrFail();
    }

    /** @param  array<int, array<string, mixed>>  $sections */
    private function save(array $sections, array $globals = []): TestResponse
    {
        return $this->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => json_encode(['sections' => $sections, 'globals' => $globals])]);
    }

    #[Test]
    public function kutuphane_duzenleme_formu_tasimaz_sablon_olusturur_kopyalar_adlandirir_siler_ve_editore_acar(): void
    {
        $ops = $this->staff('operations_admin');

        $page = $this->actingAs($ops)->get('/panel/icerik/bloklar')->assertOk();
        $page->assertSee('Blok kütüphanesi')->assertSee('Hazır bileşenler')->assertSee('Tasarımda düzenle')->assertSee('+ Yeni blok şablonu')
            ->assertDontSee('Kaydet ve yayınla')->assertDontSee('Metinleri yayınla')->assertDontSee('name="text"', false);

        // Yeni şablon: tipin varsayılanıyla oluşur, editörde ?ekle= ile açılır.
        $this->actingAs($ops)->post('/panel/icerik/bloklar/kayitli', ['name' => 'Kurumsal CTA', 'type' => 'cta_banner', 'category' => 'cta', 'is_global' => 1])
            ->assertRedirect('/panel/icerik/tasarim?website='.$this->site->id.'&ekle=preset%3A1');
        $preset = SiteBlockPreset::query()->firstOrFail();
        $this->assertTrue($preset->is_global);
        $this->assertSame('cta', $preset->category);
        $this->assertSame('Mesaj', $preset->settings['title']);
        $this->actingAs($ops)->from('/panel/icerik/bloklar')->post('/panel/icerik/bloklar/kayitli', ['name' => 'x', 'type' => 'yok', 'category' => 'cta'])->assertSessionHasErrors('type');

        // Kopya normal bloktur; ad/kategori/global güncellenir; kategori süzgeci.
        $this->actingAs($ops)->post("/panel/icerik/bloklar/kayitli/{$preset->id}/kopyala")->assertRedirect();
        $copy = SiteBlockPreset::query()->where('name', 'Kurumsal CTA (kopya)')->firstOrFail();
        $this->assertFalse($copy->is_global);
        $this->actingAs($ops)->put("/panel/icerik/bloklar/kayitli/{$copy->id}", ['name' => 'SSS bloğu', 'category' => 'faq'])->assertRedirect();
        $this->assertSame(['SSS bloğu', 'faq', false], [$copy->fresh()->name, $copy->fresh()->category, $copy->fresh()->is_global]);
        $this->actingAs($ops)->get('/panel/icerik/bloklar?kategori=faq')->assertOk()->assertSee('SSS bloğu')->assertDontSee('Kurumsal CTA');

        // Önizleme sayfası + imzalı tek blok görünümü; editör penceresi aynı kataloğu taşır.
        $this->actingAs($ops)->get("/panel/icerik/bloklar/kayitli/{$preset->id}/onizleme")->assertOk()->assertSee('preset='.$preset->id, false)->assertSee('Tasarımda düzenle');
        $single = $this->get(URL::temporarySignedRoute('site.preview', now()->addMinutes(5), ['website' => $this->site->id, 'preset' => $preset->id]))->assertOk()->getContent();
        $this->assertSame(1, substr_count($single, 'class="site-section'));
        $this->assertStringContainsString('dark-band', $single);
        $this->actingAs($ops)->get('/panel/icerik/tasarim')->assertOk()->assertSee('modal-block-library', false)->assertSee('Kütüphaneyi yönet')->assertSee('data-add-type="preset:'.$preset->id.'"', false);

        $this->actingAs($ops)->delete("/panel/icerik/bloklar/kayitli/{$copy->id}")->assertRedirect();
        $this->assertNull($copy->fresh());
        $this->actingAs($this->staff('finance_admin'))->get('/panel/icerik/bloklar')->assertForbidden();
    }

    #[Test]
    public function global_blok_bagli_tum_kullanimlari_gunceller_normal_blok_kopyalanir_silinince_bag_kopar(): void
    {
        $ops = $this->staff('operations_admin');
        $admin = $this->staff('system_admin');
        $builder = app(SiteBuilderService::class);
        $draft = $builder->draft($this->site)->keyBy('type');
        $global = $builder->savePreset($ops, $this->site, 'Kurumsal CTA', 'cta_banner', ['title' => 'Global mesaj', 'cta' => ['action' => 'lead_form', 'label' => 'Teklif al'], 'style' => 'dark'], 'cta', true);
        $normal = $builder->savePreset($ops, $this->site, 'Serbest metin', 'rich_text', ['title' => 'Normal blok', 'body' => 'Metin.'], 'icerik', false);

        // İki global kullanım + bir normal kopya.
        $base = [['id' => $draft['hero']->id, 'type' => 'hero', 'is_visible' => true, 'settings' => []]];
        $this->actingAs($ops)->save(array_merge($base, [
            ['id' => null, 'type' => 'cta_banner', 'preset_id' => $global->id, 'is_visible' => true, 'settings' => $global->settings],
            ['id' => null, 'type' => 'rich_text', 'preset_id' => $normal->id, 'is_visible' => true, 'settings' => $normal->settings],
            ['id' => null, 'type' => 'cta_banner', 'preset_id' => $global->id, 'anchor' => 'alt-cta', 'is_visible' => true, 'settings' => $global->settings],
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $rows = SiteSection::query()->orderBy('sort_order')->get();
        $this->assertSame([null, $global->id, $normal->id, $global->id], $rows->pluck('preset_id')->all());
        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla")->assertRedirect();
        $this->assertSame(2, substr_count($this->get('http://localhost/')->getContent(), 'Global mesaj'));

        // Yalnız BİRİ düzenlenir → global blok ve bağlı diğer kullanım (yayındaki dahil, yeniden yayın olmadan) güncellenir.
        $edited = $rows->map(fn (SiteSection $s) => ['id' => $s->id, 'type' => $s->type, 'preset_id' => $s->preset_id, 'is_visible' => true, 'settings' => $s->settings])->all();
        $edited[1]['settings']['title'] = 'Güncel global mesaj';
        $edited[2]['settings']['title'] = 'Normal blok değişti';
        $this->actingAs($ops)->save($edited)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Güncel global mesaj', $global->fresh()->settings['title']);
        $this->assertSame('Güncel global mesaj', SiteSection::query()->find($rows[3]->id)->settings['title'], 'İkinci kullanım eşitlendi.');
        $this->assertSame('Normal blok', $normal->fresh()->settings['title'], 'Normal blok şablonu değişmez (kopya).');
        $live = $this->get('http://localhost/')->getContent();
        $this->assertSame(2, substr_count($live, 'Güncel global mesaj'));
        $this->assertStringNotContainsString('Normal blok değişti', $live, 'Normal bölüm değişikliği yayın ister.');
        $usage = $builder->usage($this->site);
        $this->assertCount(2, $usage['presets'][$global->id]['draft']);
        $this->assertSame(2, $usage['presets'][$global->id]['published']);
        $this->actingAs($ops)->get('/panel/icerik/bloklar')->assertOk()->assertSee('Ana sayfa (taslak, 2)');

        // Bağı kopar: bölüm kendi kopyasıyla kalır, blok değişince artık etkilenmez.
        $edited[3]['preset_id'] = null;
        $edited[3]['settings']['title'] = 'Kopmuş kopya';
        $this->actingAs($ops)->save($edited)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(SiteSection::query()->find($rows[3]->id)->preset_id);
        $this->assertSame('Kopmuş kopya', SiteSection::query()->find($rows[3]->id)->settings['title']);
        $this->assertSame('Güncel global mesaj', $global->fresh()->settings['title']);

        // Global bayrağı kapatılınca kullanım kendi kopyasıdır; blok silinince bağlı bölüm son ayarı kopya olarak alır.
        $this->actingAs($ops)->put("/panel/icerik/bloklar/kayitli/{$global->id}", ['name' => 'Kurumsal CTA', 'category' => 'cta'])->assertRedirect();
        $this->assertFalse($global->fresh()->is_global);
        $this->actingAs($ops)->delete("/panel/icerik/bloklar/kayitli/{$global->id}")->assertRedirect();
        $this->assertNull(SiteSection::query()->find($rows[1]->id)->preset_id);
        $this->assertSame('Güncel global mesaj', SiteSection::query()->find($rows[1]->id)->settings['title']);
        $live = $this->get('http://localhost/')->getContent();
        $this->assertStringContainsString('Global mesaj', $live, 'Bağ kalkınca yayın anlık görüntüsü kendi (yayın anındaki) ayarıyla çizilir; güncel metin yeniden yayın ister.');
        $this->assertStringNotContainsString('Güncel global mesaj', $live);
    }

    #[Test]
    public function vitrin_veri_listeleri_editorde_bolumden_duzenlenir_taslak_onizlemede_yayinla_sitede(): void
    {
        $ops = $this->staff('operations_admin');
        $admin = $this->staff('system_admin');
        $draft = app(SiteBuilderService::class)->draft($this->site)->keyBy('type');
        $sections = $draft->map(fn (SiteSection $s) => ['id' => $s->id, 'type' => $s->type, 'is_visible' => true, 'anchor' => $s->anchor, 'settings' => $s->settings])->values()->all();

        // Editör sayfası veri listelerini bölüm eşlemesiyle taşır (eski /bloklar formunun yerine).
        $this->actingAs($ops)->get('/panel/icerik/tasarim')->assertOk()->assertSee('dataBlockSections', false)->assertSee('Fiber ve yedek hat');

        // Bozuk satır kaydı durdurur; doğru satır taslağa girer, önizlemede görünür, canlıda görünmez.
        $this->actingAs($ops)->from('/panel/icerik/tasarim')->save($sections, ['blocks' => ['amenities' => 'Eksik alan']])->assertSessionHasErrors('builder');
        $this->actingAs($ops)->save($sections, ['blocks' => ['amenities' => "Fiber | 1 Gbps yedekli hat\nResepsiyon | Hafta içi 09–19", 'pricing_note' => 'Fiyatlar KDV hariçtir.']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Fiyatlar KDV hariçtir.', $this->site->fresh()->builder_globals['blocks']['pricing_note']);
        $preview = $this->get(app(SiteBuilderService::class)->previewUrl($this->site))->assertOk()->getContent();
        $this->assertStringContainsString('1 Gbps yedekli hat', $preview);
        $this->assertStringContainsString('Fiyatlar KDV hariçtir.', $preview);
        $this->assertStringNotContainsString('1 Gbps yedekli hat', $this->get('http://localhost/')->getContent());

        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla")->assertRedirect();
        $live = $this->get('http://localhost/')->getContent();
        $this->assertStringContainsString('1 Gbps yedekli hat', $live);
        $this->assertStringContainsString('Fiyatlar KDV hariçtir.', $live);
        $this->assertSame('Fiyatlar KDV hariçtir.', SiteBlock::query()->where('key', 'pricing_note')->firstOrFail()->data);
        $this->assertNull($this->site->fresh()->builder_globals);
    }
}
