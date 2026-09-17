<?php

namespace Tests\Feature\Panel;

use App\Content\BodyRenderer;
use App\Content\GeoSuggester;
use App\Content\SeoAnalyzer;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\ContentDraft;
use App\Models\Media;
use App\Models\Website;
use App\Security\MalwareScanner;
use App\Security\ScanResult;
use App\Services\ContentCache;
use App\Services\SeoService;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 48 — CMS stüdyo: sayfa düzeyi SEO/GEO/şema alanları, gövde blokları ve kısa kodlar, imzalı önizleme,
 * kaydet-ve-yayınla izinleri, çalışma taslağında stüdyo alanları, bağlantı denetimi, kırpma.
 */
class CmsStudioTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->website = Website::query()->default()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function pagePayload(string $title = 'Sanal ofis nedir'): array
    {
        return [
            'website_id' => $this->website->id,
            'kind' => 'page',
            'title' => $title,
            'excerpt' => 'Sanal ofis hizmetinin ne olduğunu ve şirketinize nasıl yasal adres sağladığını açıklar.',
            'body' => "## Sanal ofis nasıl çalışır?\n\nSanal ofis, şirketinize yasal adres sağlar. [İletişim](/iletisim)\n\n![Ofis](/media/ofis.jpg){left width=40%}\n\n[youtube:dQw4w9WgXcQ]",
            'meta_title' => 'Sanal ofis nedir? Şirketiniz için yasal adres çözümü',
            'meta_description' => 'Sanal ofis ile şirketinize resmî adres, posta yönetimi ve toplantı odası; aynı gün kurulum.',
            'focus_keyword' => 'sanal ofis',
            'related_keywords' => 'yasal adres, posta yönetimi',
            'canonical_url' => '/sanal-ofis-nedir',
            'robots' => 'index, nofollow',
            'og_title' => 'Sanal ofis — OG başlığı',
            'og_description' => 'OG açıklaması.',
            'schema_types' => ['WebPage', 'FAQPage', 'Service'],
            'schema_custom' => '{"@type":"Thing","name":"Özel düğüm"}',
            'geo' => [
                'summary' => 'Sanal ofis hizmetinin özeti.',
                'topic' => 'Sanal ofis',
                'questions' => "Sanal ofis nedir?\nKaç günde kurulur?",
                'faq' => "Sanal ofis nedir? | Şirketinize yasal adres sağlayan hizmettir.\nKaç günde kurulur? | Aynı gün.",
            ],
        ];
    }

    #[Test]
    public function sayfa_studyo_alanlariyla_olusur_skor_hesaplanir_head_ve_semaya_yansir(): void
    {
        $admin = $this->staff('system_admin');

        $this->actingAs($admin)->post('/panel/icerik', $this->pagePayload())->assertRedirect();

        $page = Content::where('slug', 'sanal-ofis-nedir')->firstOrFail();
        $this->assertSame('sanal ofis', $page->focus_keyword);
        $this->assertSame(['yasal adres', 'posta yönetimi'], $page->related_keywords);
        $this->assertSame('index, nofollow', $page->robots);
        $this->assertSame(['WebPage', 'FAQPage', 'Service'], $page->schema_types);
        $this->assertSame('Sanal ofis', $page->geo['topic']);
        $this->assertSame([['q' => 'Sanal ofis nedir?', 'a' => 'Şirketinize yasal adres sağlayan hizmettir.'], ['q' => 'Kaç günde kurulur?', 'a' => 'Aynı gün.']], $page->geo['faq']);
        $this->assertTrue($page->geoReady());
        $this->assertNotNull($page->seo_score);
        $this->assertSame(SeoAnalyzer::analyze(['title' => $page->title, 'slug' => $page->slug, 'excerpt' => $page->excerpt, 'body' => $page->body, 'meta_title' => $page->meta_title, 'meta_description' => $page->meta_description, 'focus_keyword' => $page->focus_keyword, 'canonical_url' => $page->canonical_url, 'schema_types' => $page->schema_types, 'og_title' => $page->og_title, 'cover' => null, 'kind' => 'page'])['score'], $page->seo_score);

        $page->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subMinute()])->save();
        app(ContentCache::class)->invalidate($this->website);
        $head = app(SeoService::class)->head($this->website, $page);
        $this->assertStringEndsWith('/sanal-ofis-nedir', (string) $head['canonical']);
        $this->assertSame('index, nofollow', $head['robots']);
        $this->assertSame('Sanal ofis — OG başlığı', $head['og_title']);
        $this->assertSame('OG açıklaması.', $head['og_description']);

        $types = array_column($head['json_ld']['@graph'], '@type');
        $this->assertContains('WebPage', $types);
        $this->assertContains('FAQPage', $types);
        $this->assertContains('Service', $types);
        $this->assertContains('Thing', $types);
        $this->assertNotContains('BreadcrumbList', $types, 'Seçim yapıldıysa yalnız seçilen sayfa şemaları basılır.');

        $html = $this->get('/sanal-ofis-nedir')->assertOk()->getContent();
        $this->assertStringContainsString('content="index, nofollow"', $html);
        $this->assertStringContainsString('figure class="content-img img-left" style="width:40%"', $html);
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $html);
        $this->assertStringContainsString('Özel düğüm', $html);

        // Liste: SEO skoru ve GEO durumu sütunları; mevcut yapı korunur.
        $this->actingAs($admin)->get('/panel/icerik?kind=page')->assertOk()->assertSee('hazır')->assertSee((string) $page->seo_score);
    }

    #[Test]
    public function govde_bloklari_kisa_kodlar_ve_izinli_gomme_guvenli_cizilir(): void
    {
        $renderer = app(BodyRenderer::class);

        $html = $renderer->render(":::cta\r\ntitle: Hemen başlayın\r\ntext: <b>x</b>\r\nbutton: Teklif al\r\nlink: /iletisim\r\n:::\r\n\r\n:::faq\n- Soru? | Cevap\n:::\n\n:::bilinmeyen\nmetin\n:::\n\n[button:Ara](/iletisim)\n\n[embed:https://player.vimeo.com/video/1]\n\n[embed:https://kotu.example/x]\n\n<script>alert(1)</script>");

        $this->assertStringContainsString('content-block--cta', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html, 'Blok alanları kaçırılır.');
        $this->assertStringContainsString('href="/iletisim" class="btn', $html);
        $this->assertStringContainsString('content-block--faq', $html);
        $this->assertStringContainsString(':::bilinmeyen', $html, 'Bilinmeyen blok metin olarak kalır.');
        $this->assertStringContainsString('class="btn btn--brand btn--pill content-cta">Ara</a>', $html);
        $this->assertStringContainsString('<iframe src="https://player.vimeo.com/video/1"', $html);
        $this->assertStringContainsString('izin verilmeyen kaynak', $html);
        $this->assertStringNotContainsString('kotu.example', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    #[Test]
    public function onizleme_imzali_baglantiyla_yayinlanmamis_icerigi_gosterir_ve_noindex_basar(): void
    {
        $admin = $this->staff('system_admin');
        $this->actingAs($admin)->post('/panel/icerik', $this->pagePayload('Önizleme sayfası') + ['then' => 'preview'])
            ->assertRedirect('/panel/icerik/'.Content::where('slug', 'onizleme-sayfasi')->value('id').'/onizleme');
        $page = Content::where('slug', 'onizleme-sayfasi')->firstOrFail();
        $this->assertSame(ContentStatus::DRAFT, $page->status);

        // Vitrin: taslak 404, imzasız önizleme 403, imzalı önizleme görünür + noindex.
        $this->get('/onizleme-sayfasi')->assertNotFound();
        $this->get('/onizleme/icerik/'.$page->id)->assertForbidden();
        $signed = URL::temporarySignedRoute('site.preview.content', now()->addMinutes(5), ['content' => $page->id]);
        $html = $this->get($signed)->assertOk()->getContent();
        $this->assertStringContainsString('Önizleme sayfası', $html);
        $this->assertStringContainsString('noindex', $html);

        // Panel önizleme sayfası çerçeveyi ve cihaz seçiciyi basar.
        $this->actingAs($admin)->get("/panel/icerik/{$page->id}/onizleme")->assertOk()->assertSee('data-preview-devices', false)->assertSee('/onizleme/icerik/'.$page->id);

        // Çalışma taslağı önizlemesi: taslak metni bellekte bindirilir, yayındaki metin değişmez.
        $page->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subMinute()])->save();
        app(ContentCache::class)->invalidate($this->website);
        $this->actingAs($admin)->post("/panel/icerik/{$page->id}/taslak")->assertRedirect();
        $this->actingAs($admin)->put("/panel/icerik/{$page->id}/taslak", ['title' => 'Taslak başlığı', 'body' => 'Taslak gövdesi.', 'focus_keyword' => 'taslak', 'then' => 'preview'])
            ->assertRedirect("/panel/icerik/{$page->id}/onizleme?draft=1");
        $this->assertSame('taslak', ContentDraft::where('content_id', $page->id)->firstOrFail()->focus_keyword);
        $draftSigned = URL::temporarySignedRoute('site.preview.content', now()->addMinutes(5), ['content' => $page->id, 'draft' => 1]);
        $this->get($draftSigned)->assertOk()->assertSee('Taslak gövdesi.');
        $this->get('/onizleme-sayfasi')->assertOk()->assertDontSee('Taslak gövdesi.');
        $this->assertSame('Önizleme sayfası', $page->fresh()->title);
    }

    #[Test]
    public function kaydet_ve_yayinla_iki_izin_ister_ve_dogrudan_yayina_alir(): void
    {
        $ops = $this->staff('operations_admin'); // content.edit var, content.publish yok
        $admin = $this->staff('system_admin');

        $this->actingAs($ops)->post('/panel/icerik/yayinla', $this->pagePayload('Yetkisiz yayın'))->assertForbidden();
        $this->assertNull(Content::where('slug', 'yetkisiz-yayin')->first());

        $this->actingAs($admin)->post('/panel/icerik/yayinla', $this->pagePayload('Yetkili yayın'))->assertRedirect();
        $page = Content::where('slug', 'yetkili-yayin')->firstOrFail();
        $this->assertSame(ContentStatus::PUBLISHED, $page->status);
        $this->get('/yetkili-yayin')->assertOk();

        // Düzenleme formu stüdyo sekmelerini ve Yayınla düğmesini yalnız yetkiliye basar.
        $draft = Content::create(['website_id' => $this->website->id, 'kind' => 'page', 'slug' => 'taslak-sayfa', 'title' => 'Taslak sayfa', 'body' => 'x', 'reading_minutes' => 1]);
        $this->actingAs($admin)->get("/panel/icerik/{$draft->id}/duzenle")->assertOk()->assertSee('GEO / AI')->assertSee('kaydet-ve-yayinla');
        $this->actingAs($ops)->get("/panel/icerik/{$draft->id}/duzenle")->assertOk()->assertDontSee('kaydet-ve-yayinla');
        $update = array_diff_key($this->pagePayload('Taslak sayfa'), ['website_id' => 1, 'kind' => 1]);
        $this->actingAs($ops)->put("/panel/icerik/{$draft->id}/kaydet-ve-yayinla", $update)->assertForbidden();
        $this->actingAs($admin)->put("/panel/icerik/{$draft->id}/kaydet-ve-yayinla", $update)->assertRedirect();
        $this->assertSame(ContentStatus::PUBLISHED, $draft->fresh()->status);
    }

    #[Test]
    public function baglanti_denetimi_kirik_ve_yetim_sayfayi_bulur(): void
    {
        $seo = app(SeoService::class);
        $target = Content::create(['website_id' => $this->website->id, 'kind' => 'page', 'slug' => 'hedef', 'title' => 'Hedef', 'body' => 'Bağlantısız.', 'reading_minutes' => 1]);
        $target->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subDay(), 'show_in_nav' => false])->save();

        $audit = $seo->linkAudit($this->website, 'Bkz. [hedef](/hedef), [yok](/olmayan-sayfa) ve [dış](https://example.com/x).', null);
        $this->assertSame(['/olmayan-sayfa'], $audit['broken']);
        $this->assertSame(2, $audit['outbound']);
        $this->assertNull($audit['inbound']);

        $this->assertSame(0, $seo->linkAudit($this->website, (string) $target->body, $target)['inbound'], 'Kimse bağlamıyor: yetim.');

        $source = Content::create(['website_id' => $this->website->id, 'kind' => 'page', 'slug' => 'kaynak', 'title' => 'Kaynak', 'body' => 'Bkz. [hedef](/hedef).', 'reading_minutes' => 1]);
        $source->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subDay(), 'show_in_nav' => false])->save();
        app(ContentCache::class)->invalidate($this->website);
        $this->assertSame(1, $seo->linkAudit($this->website, (string) $target->body, $target)['inbound']);
    }

    #[Test]
    public function seo_analizi_ve_geo_onerileri_deterministik_calisir(): void
    {
        $weak = SeoAnalyzer::analyze(['title' => 'Kısa', 'body' => '# H1 var', 'schema_types' => []]);
        $strong = SeoAnalyzer::analyze([
            'title' => 'Sanal ofis nedir? Şirketiniz için yasal adres çözümü', 'slug' => 'sanal-ofis-nedir',
            'meta_description' => 'Sanal ofis ile şirketinize resmî adres, posta yönetimi ve toplantı odası; aynı gün kurulum.',
            'body' => "Sanal ofis giriş.\n\n## Bölüm\n\n".str_repeat('sanal ofis kelime ', 120)."\n\n[İç](/iletisim)\n\n![Görsel](/x.jpg)",
            'focus_keyword' => 'sanal ofis', 'schema_types' => ['WebPage'], 'og_title' => 'x',
        ]);
        $this->assertLessThan(50, $weak['score']);
        $this->assertGreaterThanOrEqual(80, $strong['score']);
        $this->assertSame('Zayıf', SeoAnalyzer::scoreLabel($weak['score']));
        $keys = array_column($weak['checks'], 'ok', 'key');
        $this->assertFalse($keys['h1']);
        $this->assertFalse($keys['title']);

        $suggested = GeoSuggester::suggest(['title' => 'Sanal ofis nedir', 'excerpt' => 'Kısa özet.', 'body' => "## Sanal ofis nedir?\n\nCevap paragrafı.\n\n## Nasıl kurulur?\n\nAdımlar.", 'focus_keyword' => 'sanal ofis', 'kind' => 'page'], collect(), ['Levent', 'Sanal Ofis']);
        $this->assertSame('Kısa özet.', $suggested['summary']);
        $this->assertStringContainsString('Sanal ofis nedir?', $suggested['questions']);
        $this->assertStringContainsString('Nasıl kurulur? | Adımlar.', $suggested['faq']);
        $this->assertStringContainsString('Sanal Ofis', $suggested['entities']);

        // Normalize: satırlı form → kayıt biçimi; toForm geri çevirir.
        $geo = GeoSuggester::normalize(['faq' => "Soru? | Cevap\nBoş soru olmaz |", 'questions' => " A?\n\nB? "]);
        $this->assertSame([['q' => 'Soru?', 'a' => 'Cevap'], ['q' => 'Boş soru olmaz', 'a' => '']], $geo['faq']);
        $this->assertSame(['A?', 'B?'], $geo['questions']);
        $this->assertSame("A?\nB?", GeoSuggester::toForm($geo)['questions']);
    }

    #[Test]
    public function kirpma_yeni_medya_olarak_karantina_zincirinden_gecer_ve_editore_doner(): void
    {
        Storage::fake('public');
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(string $path): ScanResult
            {
                return ScanResult::clean();
            }
        });
        $admin = $this->staff('system_admin');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);

        $this->actingAs($admin)->post('/panel/icerik/medya?website='.$this->website->id, [
            'file' => UploadedFile::fake()->createWithContent('Kapak Görseli.png', (string) $png),
            'alt' => 'Kapak', 'title' => 'Başlık', 'caption' => 'Açıklama', 'seo_name' => 'sanal-ofis-istanbul', 'return' => '/panel/icerik/yeni?kind=page',
        ])->assertRedirect('/panel/icerik/yeni?kind=page');
        $media = Media::query()->firstOrFail();
        $this->assertSame('sanal-ofis-istanbul.png', $media->original_name);
        $this->assertSame('Başlık', $media->title);
        $this->assertSame('Açıklama', $media->caption);

        $im = imagecreatetruecolor(2, 2);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 30, 30));
        ob_start();
        imagepng($im);
        $croppedPng = (string) ob_get_clean();
        $this->actingAs($admin)->post("/panel/icerik/medya/{$media->id}/kirp?website=".$this->website->id, ['image' => 'data:image/png;base64,'.base64_encode($croppedPng), 'return' => '/panel/icerik/yeni?kind=page'])
            ->assertRedirect('/panel/icerik/yeni?kind=page');
        $this->assertSame(2, Media::count());
        $cropped = Media::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('sanal-ofis-istanbul-kirpilmis.png', $cropped->original_name);
        $this->assertSame('Kapak', $cropped->alt);

        // Geçersiz veri ve panel dışı dönüş adresi reddedilir.
        $this->actingAs($admin)->post("/panel/icerik/medya/{$media->id}/kirp?website=".$this->website->id, ['image' => 'data:text/html;base64,PGI+', 'return' => '/panel/icerik/yeni'])->assertSessionHasErrors('image');
        $this->actingAs($admin)->put("/panel/icerik/medya/{$media->id}?website=".$this->website->id, ['alt' => 'Yeni alt', 'return' => 'https://kotu.example/'])
            ->assertRedirect('/panel/icerik/medya?website='.$this->website->id);
        $this->assertSame('Yeni alt', $media->fresh()->alt);
    }
}
