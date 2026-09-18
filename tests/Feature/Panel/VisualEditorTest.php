<?php

namespace Tests\Feature\Panel;

use App\Models\Content;
use App\Models\Media;
use App\Models\SiteBlockPreset;
use App\Models\SiteRevision;
use App\Models\SiteSection;
use App\Models\Website;
use App\Security\MalwareScanner;
use App\Security\ScanResult;
use App\Services\SiteBuilderService;
use App\Site\SectionStyle;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 49 — Görsel site editörü: gerçek vitrin çerçevesi (yalnız imzalı ?editor=1 işaretli), tek gönderimli
 * taslak kaydı (sıra/ekle/sil/kilit/stil/alan biçimi/görsel bırakma/global metin), doğrulama ve allowlist,
 * yayın ile globallerin canlıya geçmesi, kayıtlı bloklar, editörden sayfa oluşturma, sürüm önizleme.
 */
class VisualEditorTest extends TestCase
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

    private function editorFrameUrl(): string
    {
        return URL::temporarySignedRoute('site.preview', now()->addMinutes(5), ['website' => $this->site->id, 'editor' => 1]);
    }

    #[Test]
    public function editor_gercek_vitrini_isaretli_cerceveler_canliya_editor_kodu_gitmez(): void
    {
        $ops = $this->staff('operations_admin');
        $admin = $this->staff('system_admin');

        $page = $this->actingAs($ops)->get('/panel/icerik/tasarim')->assertOk();
        $page->assertSee('Ana sayfa tasarımı')->assertSee('data-ve', false)->assertSee('editor=1', false)->assertSee('Katmanlar')->assertSee('Kolonlar (satır)')->assertSee('✦ AI')->assertDontSee('Yayınla</button>', false);
        $this->actingAs($admin)->get('/panel/icerik/tasarim')->assertOk()->assertSee('Yayınla</button>', false)->assertSee('+ Yeni sayfa');

        // Editör çerçevesi: bölüm/alan/global işaretleri, şablonlar, editör betiği; menü taslağın çapalarından.
        $frame = $this->get($this->editorFrameUrl())->assertOk()->getContent();
        $this->assertStringContainsString('data-ofv-section="', $frame);
        $this->assertStringContainsString('data-ofv-field="title"', $frame);
        $this->assertStringContainsString('data-ofv-global="texts.cta_header"', $frame);
        $this->assertStringContainsString('data-ofv-template="features"', $frame);
        $this->assertStringContainsString('data-ofv-template="hero"', $frame);
        $this->assertStringContainsString('site-editor-frame.js', $frame);
        $this->assertStringContainsString('noindex', $frame);

        // Canlı vitrin ve normal önizleme: işaret/betik YOK (performans + güvenlik).
        $live = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ofv', $live);
        $this->assertStringNotContainsString('site-editor-frame.js', $live);
        $this->assertStringContainsString('class="site-section', $live);
        $preview = $this->get(app(SiteBuilderService::class)->previewUrl($this->site))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ofv', $preview);
        $this->assertStringNotContainsString('data-ofv-template', $preview);

        // İmzasız editör çerçevesi 403.
        $this->get('/onizleme/'.$this->site->id.'?editor=1')->assertForbidden();
    }

    #[Test]
    public function tek_gonderimli_taslak_kaydi_sira_ekle_sil_stil_gorsel_ve_global_metinleri_yazar_yayin_canliya_gecirir(): void
    {
        Storage::fake('public');
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(string $path): ScanResult
            {
                return ScanResult::clean();
            }
        });
        $ops = $this->staff('operations_admin');
        $admin = $this->staff('system_admin');

        $draft = app(SiteBuilderService::class)->draft($this->site)->keyBy('type');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        $payload = [
            'sections' => [
                ['id' => $draft['solutions']->id, 'type' => 'solutions', 'anchor' => 'cozumler', 'is_visible' => true, 'label' => 'Hizmet kartları', 'locked' => true, 'settings' => ['title' => 'Çözüm <b>kartları</b>', 'style' => ['bg' => 'dark', 'pt' => 999, 'cols' => 3, 'junk' => 'x'], 'style_mobile' => ['cols' => 1, 'pt' => 24], 'field_styles' => ['title' => ['size' => 44, 'color' => '#ff0000', 'weight' => '700', 'bogus' => 1], 'lede' => ['color' => 'red']]]],
                ['id' => $draft['hero']->id, 'type' => 'hero', 'anchor' => null, 'is_visible' => true, 'settings' => ['cta' => ['action' => 'lead_form', 'label' => 'Teklif al']]],
                ['id' => null, 'type' => 'features', 'anchor' => 'ozellikler', 'is_visible' => true, 'settings' => ['title' => 'Neden biz', 'items' => ['✓ | Yasal adres | Resmî yazışma adresi | /hizmetler', '✓ | Posta | Bildirim'], 'style' => ['align' => 'center', 'radius' => 24]]],
                ['id' => null, 'type' => 'image', 'anchor' => 'gorsel', 'is_visible' => true, 'settings' => ['media' => 'upload:drop1', 'caption' => 'Bırakılan görsel', 'link' => '/hizmetler', 'width' => 60]],
                ['id' => null, 'type' => 'gallery', 'is_visible' => true, 'settings' => ['media' => ['upload:drop1', 'upload:yok', 'abc'], 'ratio' => '1/1']],
                ['id' => $draft['lead_form']->id, 'type' => 'lead_form', 'anchor' => 'teklif', 'is_visible' => false, 'hide_on_mobile' => true, 'settings' => []],
            ],
            'globals' => ['texts' => ['cta_header' => 'Hemen teklif al', 'nav_solutions' => 'Çözümler'], 'footer_columns' => 'Kurumsal | Yazılar = /blog, Teklif = #teklif', 'hero_media' => 'upload:drop1'],
        ];

        $this->actingAs($ops)->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => json_encode($payload), 'uploads' => ['drop1' => UploadedFile::fake()->createWithContent('kapak.png', (string) $png)]])
            ->assertRedirect('/panel/icerik/tasarim?website='.$this->site->id.'&cihaz=desktop')->assertSessionHasNoErrors();

        // DB: sıra + yeni + silinen; stil allowlist ve kısıt; alan biçimi süzülür; görsel yüklendi ve bağlandı.
        $rows = SiteSection::query()->where('website_id', $this->site->id)->orderBy('sort_order')->get();
        $this->assertSame(['solutions', 'hero', 'features', 'image', 'gallery', 'lead_form'], $rows->pluck('type')->all());
        $solutions = $rows[0];
        $this->assertTrue($solutions->locked);
        $this->assertSame('Hizmet kartları', $solutions->label);
        $this->assertSame(['bg' => 'dark', 'pt' => 240, 'cols' => 3], $solutions->settings['style']);
        $this->assertSame(['pt' => 24, 'cols' => 1], $solutions->settings['style_mobile']);
        $this->assertSame(['title' => ['size' => 44, 'color' => '#ff0000', 'weight' => '700']], $solutions->settings['field_styles']);
        $media = Media::query()->firstOrFail();
        $this->assertSame('kapak', $media->alt);
        $this->assertSame($media->id, $rows[3]->settings['media']);
        $this->assertSame([$media->id], $rows[4]->settings['media'], 'Geçersiz yükleme/id düşer.');
        $this->assertSame('60', $rows[3]->settings['width']);
        $this->assertFalse($rows[5]->is_visible);
        $this->assertNull($this->site->fresh()->hero_media_id, 'operations_admin website.manage taşımaz: hero görseli değişmez.');
        $globals = $this->site->fresh()->builder_globals;
        $this->assertSame(['cta_header' => 'Hemen teklif al'], $globals['texts'], 'Yalnız canlıdan farklı metin taslağa girer.');
        $this->assertStringContainsString('Kurumsal | Yazılar', $globals['footer_columns']);
        $this->assertTrue(app(SiteBuilderService::class)->hasUnpublishedChanges($this->site));

        // Önizleme (taslak): stil değişkenleri, sınıflar, cihaz CSS'i, kolonlar, alan biçimi, escape, global metin + footer taslağı.
        $preview = $this->get(app(SiteBuilderService::class)->previewUrl($this->site))->assertOk()->getContent();
        $this->assertStringContainsString('class="site-section sec-bg-dark', $preview);
        $this->assertStringContainsString('--sec-pt:240px', $preview);
        $this->assertStringContainsString('--sec-bg:var(--dark, #14201b)', $preview);
        $this->assertStringContainsString('data-cols="3"', $preview);
        $this->assertStringContainsString('data-cols-m="1"', $preview);
        $this->assertStringContainsString('@media (max-width: 640px){#sec-'.$solutions->id.'{--sec-pt:24px !important;--sec-cols:1 !important}}', $preview);
        $this->assertStringContainsString('style="font-size:44px;color:#ff0000;font-weight:700"', $preview);
        $this->assertStringContainsString('Çözüm &lt;b&gt;kartları&lt;/b&gt;', $preview);
        $this->assertStringContainsString('Neden biz', $preview);
        $this->assertStringContainsString('href="/hizmetler">Yasal adres</a>', $preview);
        $this->assertStringContainsString('Bırakılan görsel', $preview);
        $this->assertStringContainsString('width:60%', $preview);
        $this->assertStringContainsString('>Hemen teklif al<', $preview);
        $this->assertStringContainsString('Yazılar</a>', $preview);
        $this->assertStringNotContainsString('data-ofv', $preview);

        // Canlı: hiçbiri yok.
        $live = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Neden biz', $live);
        $this->assertStringNotContainsString('Hemen teklif al', $live);
        $this->assertStringContainsString('>Teklif Al<', $live);

        // Yayın (content.publish): revizyon + global metin/footer canlıya, taslak temizlenir.
        $this->actingAs($ops)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla")->assertForbidden();
        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla", ['note' => 'Editör'])->assertRedirect();
        $live = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('Neden biz', $live);
        $this->assertStringContainsString('>Hemen teklif al<', $live);
        $this->assertStringContainsString('sec-bg-dark', $live);
        $this->assertStringContainsString('Kurumsal', $live);
        $this->assertNull($this->site->fresh()->builder_globals);
        $this->assertFalse(app(SiteBuilderService::class)->hasUnpublishedChanges($this->site));
        $this->assertTrue((bool) SiteRevision::query()->where('number', 1)->first()?->snapshot[0]['locked']);

        // Sürüm önizlemesi: eski revizyon görünümü imzalı önizlemede.
        $this->actingAs($admin)->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => json_encode(['sections' => [['id' => $rows[2]->id, 'type' => 'features', 'is_visible' => true, 'settings' => ['title' => 'Sonraki sürüm']]]])])->assertRedirect();
        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla")->assertRedirect();
        $old = $this->get(URL::temporarySignedRoute('site.preview', now()->addMinutes(5), ['website' => $this->site->id, 'revision' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('Neden biz', $old);
        $this->assertStringContainsString('revizyon 1 görünümü', $old);
        $this->assertStringNotContainsString('Sonraki sürüm', $old);

        // Site ana görseli: website.manage taşıyan aktör (system_admin) global hero_media ile değiştirir.
        $this->actingAs($admin)->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => json_encode(['sections' => [['id' => $rows[2]->id, 'type' => 'features', 'is_visible' => true, 'settings' => ['title' => 'Sonraki sürüm']]], 'globals' => ['hero_media' => $media->id]])])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($media->id, $this->site->fresh()->hero_media_id);
    }

    #[Test]
    public function taslak_kaydi_dogrulanir_tip_degismez_tekil_bolum_bir_kez_harita_yalniz_google(): void
    {
        $ops = $this->staff('operations_admin');
        $draft = app(SiteBuilderService::class)->draft($this->site)->keyBy('type');
        $save = fn (array $sections) => $this->actingAs($ops)->from('/panel/icerik/tasarim')->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => json_encode(['sections' => $sections])]);

        $save([['id' => null, 'type' => 'yok', 'settings' => []]])->assertSessionHasErrors('builder');
        $save([['id' => $draft['hero']->id, 'type' => 'hero', 'settings' => []], ['id' => null, 'type' => 'hero', 'settings' => []]])->assertSessionHasErrors('builder');
        $save([['id' => $draft['hero']->id, 'type' => 'faq', 'settings' => []]])->assertSessionHasErrors('builder');
        $save([['id' => null, 'type' => 'map', 'settings' => ['embed' => 'https://kotu.example/embed']]])->assertSessionHasErrors('builder');
        $save([['id' => null, 'type' => 'image', 'settings' => ['link' => 'javascript:alert(1)']]])->assertSessionHasErrors('builder');
        $save([['id' => null, 'type' => 'rich_text', 'anchor' => 'Kötü Çapa', 'settings' => []]])->assertSessionHasErrors('builder');
        $this->actingAs($ops)->from('/panel/icerik/tasarim')->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => '{bozuk'])->assertSessionHasErrors('builder');
        $this->assertSame(11, SiteSection::count(), 'Hatalı gönderim hiçbir şey yazmaz.');

        // Yalnız content.edit; finans erişemez.
        $this->actingAs($this->staff('finance_admin'))->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => '{}'])->assertForbidden();

        // SectionStyle allowlist birim: sözlük dışı, tip dışı ve aralık dışı değerler.
        $this->assertSame(['bg' => 'brand', 'pt' => 0, 'mt' => -120, 'align' => 'center', 'radius' => 64, 'hidden' => true], SectionStyle::normalize(['bg' => 'brand', 'bg_color' => 'red', 'pt' => -5, 'mt' => -999, 'align' => 'center', 'radius' => '100', 'shadow' => 'huge', 'hidden' => '1', 'expression' => 'url(x)']));
        $this->assertSame(['title' => ['lh' => 1.4, 'font' => 'serif']], SectionStyle::normalizeFields(['title' => ['lh' => '1.4', 'font' => 'serif', 'size' => 'big'], 'bad key!' => ['size' => 20], 'lede' => ['color' => 'javascript:']]));
        $this->assertSame('', SectionStyle::media(['style_tablet' => ['shadow' => 'sm']], '#x'), 'Sınıf tabanlı anahtarlar medya CSS üretmez.');
    }

    #[Test]
    public function kayitli_bloklar_kutuphaneye_girer_sablon_olarak_cerceveye_gelir_ve_silinir(): void
    {
        $ops = $this->staff('operations_admin');

        $this->actingAs($ops)->post("/panel/icerik/tasarim/{$this->site->id}/blok-kaydet", ['name' => 'Kurumsal SSS', 'type' => 'faq', 'settings' => json_encode(['title' => 'Sık sorulanlar', 'items' => ['Sözleşme? | 1 ay'], 'style' => ['bg' => 'warm']])])->assertRedirect()->assertSessionHasNoErrors();
        $preset = SiteBlockPreset::query()->firstOrFail();
        $this->assertSame('faq', $preset->type);
        $this->assertSame(['bg' => 'warm'], $preset->settings['style']);
        $this->actingAs($ops)->from('/panel/icerik/tasarim')->post("/panel/icerik/tasarim/{$this->site->id}/blok-kaydet", ['name' => 'x', 'type' => 'yok', 'settings' => '{}'])->assertSessionHasErrors('builder');

        $this->actingAs($ops)->get('/panel/icerik/tasarim')->assertOk()->assertSee('Kurumsal SSS');
        $frame = $this->get($this->editorFrameUrl())->assertOk()->getContent();
        $this->assertStringContainsString('data-ofv-template="preset:'.$preset->id.'"', $frame);
        $this->assertStringContainsString('Sözleşme?', $frame);

        $this->actingAs($ops)->delete("/panel/icerik/tasarim/{$this->site->id}/blok/{$preset->id}")->assertRedirect();
        $this->assertSame(0, SiteBlockPreset::count());
    }

    #[Test]
    public function editorden_yeni_sayfa_bos_sablon_ya_da_kopya_olarak_studyoda_acilir(): void
    {
        $admin = $this->staff('system_admin');

        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/sayfa", ['title' => 'Hakkımızda', 'mode' => 'template', 'template' => 'about'])->assertRedirect();
        $about = Content::query()->where('slug', 'hakkimizda')->firstOrFail();
        $this->assertStringContainsString(':::features', (string) $about->body);
        $this->assertSame('DRAFT', $about->status->value);

        $about->forceFill(['meta_title' => 'Hakkımızda | Ofisvio', 'geo' => ['summary' => 'Özet', 'faq' => [['q' => 'S?', 'a' => 'C']]]])->save();
        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/sayfa", ['title' => 'Hakkımızda (kopya)', 'mode' => 'copy', 'source' => $about->id])->assertRedirect();
        $copy = Content::query()->where('slug', 'hakkimizda-kopya')->firstOrFail();
        $this->assertSame($about->body, $copy->body);
        $this->assertSame('Hakkımızda | Ofisvio', $copy->meta_title);
        $this->assertSame('Özet', $copy->geo['summary']);

        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/sayfa", ['title' => 'Boş sayfa', 'mode' => 'blank'])->assertRedirect('/panel/icerik/'.(Content::query()->where('slug', 'bos-sayfa')->value('id')).'/duzenle');
        $this->actingAs($admin)->from('/panel/icerik/tasarim')->post("/panel/icerik/tasarim/{$this->site->id}/sayfa", ['title' => 'Yabancı', 'mode' => 'copy', 'source' => 99999])->assertSessionHasErrors('builder');
        $this->actingAs($this->staff('operations_admin'))->post("/panel/icerik/tasarim/{$this->site->id}/sayfa", ['title' => 'Yetkisiz', 'mode' => 'blank'])->assertForbidden();

        // Sayfalar sekmesi: SEO/GEO rozetleri ve stüdyo bağlantısı.
        $this->actingAs($admin)->get('/panel/icerik/tasarim')->assertOk()->assertSee('Hakkımızda')->assertSee('GEO hazır')->assertSee('/panel/icerik/'.$about->id.'/duzenle');
    }
}
