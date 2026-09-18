<?php

namespace Tests\Feature\Panel;

use App\Content\BlogStarter;
use App\Models\Content;
use App\Models\Location;
use App\Models\Media;
use App\Models\Service;
use App\Models\SiteSection;
use App\Models\Website;
use App\Services\ContentCache;
use App\Services\RedirectService;
use Database\Seeders\ServiceSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Blog ekosistemi (faz 58): başlangıç seti komutu (kapak → SEO/GEO → iç bağlantı → yayın) → ana sayfada otomatik → kart →
 * yazı sayfası (görsel, JSON-LD, SSS, iç bağlantılar çalışır) → editörde blog bölümü ayarları vitrine yansır → yeni yazı
 * yayınlanınca otomatik listelenir; öne çıkanlar önce.
 */
class BlogEcosystemTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(ServiceSeeder::class);
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
        Storage::fake('public');
        Storage::fake('private');
        $this->site = Website::query()->default()->firstOrFail();
    }

    #[Test]
    public function baslangic_seti_kapak_seo_geo_ic_baglanti_yayin_ana_sayfa_ve_detay(): void
    {
        $admin = $this->staff('system_admin');
        $konya = Location::create(['name' => 'Konya', 'slug' => 'konya', 'city' => 'Konya', 'region' => 'Konya', 'address_line' => 'Test Cd. 1', 'is_active' => true, 'is_published' => true]);
        $konya->services()->sync(Service::query()->pluck('id')->all());

        $mediaBefore = Media::query()->count();
        $this->artisan('ofisvio:blog-starter', ['--user' => $admin->email])->assertSuccessful();
        $this->artisan('ofisvio:blog-starter', ['--user' => $admin->email])->expectsOutputToContain('0 oluşturuldu')->assertSuccessful(); // yinelenebilir

        $expected = count(BlogStarter::posts());
        $posts = Content::query()->where('kind', 'post')->whereIn('slug', collect(BlogStarter::posts())->pluck('slug'))->get(); // seed yazıları (site_blocks.json) hariç
        $this->assertCount($expected, $posts);
        $this->assertSame($expected, $posts->where('status.value', 'PUBLISHED')->count());
        $this->assertSame($expected, Media::query()->count() - $mediaBefore, 'Her yazının medya kütüphanesinde gerçek kapak kaydı olmalı.');

        foreach ($posts as $p) {
            $this->assertNotNull($p->cover_media_id, $p->slug);
            $this->assertNotEmpty($p->meta_title, $p->slug);
            $this->assertLessThanOrEqual(70, mb_strlen($p->meta_title));
            $this->assertLessThanOrEqual(160, mb_strlen($p->meta_description));
            $this->assertNotEmpty($p->focus_keyword);
            $this->assertNotEmpty($p->related_keywords);
            $this->assertGreaterThanOrEqual(3, count($p->geo['faq'] ?? []), $p->slug.' SSS');
            $this->assertNotEmpty($p->geo['summary'] ?? '', $p->slug.' GEO özeti');
            $this->assertContains('FAQPage', (array) $p->schema_types);
            $this->assertGreaterThanOrEqual(60, (int) $p->seo_score, $p->slug.' SEO skoru');
            $this->assertStringContainsString('## ', (string) $p->body);
            $this->assertStringNotContainsString('{', preg_replace('~:::[a-z]+~', '', (string) $p->body) ?? '', $p->slug.' doldurulmamış yer tutucu');
            $this->assertStringContainsString('kapak görseli', (string) Media::query()->find($p->cover_media_id)?->alt, $p->slug.' alt metni');
        }

        // Yerel yazılar şehirle; genel yazılara şehir zorlanmaz.
        $this->assertStringContainsString("Konya'da sanal ofis", Content::query()->where('slug', 'konyada-sanal-ofis')->value('title'));
        $this->assertStringNotContainsString('Konya', Content::query()->where('slug', 'hazir-ofis-nedir')->value('body'));

        // Ana sayfa: bölüm otomatik dolar — öne çıkanlar önce, 3 kart, gerçek kapak, kategori/tarih/okuma süresi/Devamını Oku, CTA.
        app(ContentCache::class)->invalidate($this->site);
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('Güncel İçerikler', $home);
        $this->assertStringContainsString('Tüm Yazıları Gör', $home);
        $this->assertSame(3, substr_count($home, 'Devamını Oku'));
        $this->assertStringContainsString('dk okuma', $home);
        $this->assertStringContainsString('/storage/media/', $home);
        $featuredSlugs = collect(BlogStarter::posts())->where('featured', true)->pluck('slug');
        foreach ($featuredSlugs as $slug) {
            $this->assertStringContainsString('/blog/'.$slug.'"', $home, 'Öne çıkan yazı ana sayfada olmalı: '.$slug);
        }

        // Kart → yazı sayfası: kapak, Article + FAQPage JSON-LD, SSS, iç bağlantılar hedefe ulaşır (200 / 301 değil 404 değil).
        $post = Content::query()->where('slug', 'konyada-sanal-ofis')->firstOrFail();
        $page = $this->get('http://localhost/blog/konyada-sanal-ofis')->assertOk()->getContent();
        $this->assertStringContainsString('<h1', $page);
        $this->assertStringContainsString('"@type":"Article"', $page);
        $this->assertStringContainsString('"@type":"FAQPage"', $page);
        $this->assertStringContainsString((string) $post->cover_url, $page);
        $this->assertStringContainsString('<h2>', $page);
        preg_match_all('~href="(/[a-z0-9/#.-]*)"~', $page, $m);
        $internal = array_values(array_unique(array_filter($m[1], fn (string $h) => ! str_starts_with($h, '/#') && $h !== '/')));
        $this->assertNotEmpty($internal);
        $this->assertContains('/cozum/sanal-ofis', $internal);
        $this->assertContains('/lokasyon/konya', $internal);
        $this->assertContains('/blog/sanal-ofis-kimler-icin-uygundur', $internal);

        foreach ($internal as $href) {
            $status = $this->get('http://localhost'.strtok($href, '#'))->getStatusCode();
            $this->assertContains($status, [200, 301, 302], "İç bağlantı kırık: {$href} → {$status}");
        }

        $this->assertTrue(app(RedirectService::class)->resolves($this->site, '/blog/sanal-ofis-nedir'));

        // Editör: blog bölümü ayarları (adet, görünüm, kategori, CTA) → kaydet → yayınla → vitrin; kartlar seçilebilir.
        $this->actingAs($admin)->get('/panel/icerik/tasarim')->assertOk()->assertSee('&quot;posts&quot;:[', false);
        $frame = $this->actingAs($admin)->get(URL::temporarySignedRoute('site.preview', now()->addMinutes(5), ['website' => $this->site->id, 'editor' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('data-ofv-card="post:'.$post->id.'"', $frame);
        $this->assertStringContainsString('data-ofv-field="title" data-ofv-global="texts.blog_title"', $frame);

        $rows = SiteSection::query()->where('website_id', $this->site->id)->orderBy('sort_order')->get()->map(fn (SiteSection $s) => ['id' => $s->id, 'type' => $s->type, 'anchor' => $s->anchor, 'is_visible' => true, 'settings' => $s->type === 'blog' ? ['title' => 'Blogdan seçtiklerimiz', 'lede' => 'Kısa açıklama.', 'limit' => '2', 'category' => 'Hazır Ofis', 'layout' => 'spotlight', 'cta' => ['action' => 'blog', 'target' => '', 'label' => 'Bütün yazılar']] : ($s->settings ?? [])])->values()->all();
        $this->actingAs($admin)->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => json_encode(['sections' => $rows, 'globals' => ['texts' => [], 'footer_columns' => '', 'blocks' => []]])])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla")->assertRedirect();
        app(ContentCache::class)->invalidate($this->site);
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('Blogdan seçtiklerimiz', $home);
        $this->assertStringContainsString('Bütün yazılar', $home);
        $this->assertSame(2, substr_count($home, 'Devamını Oku'));
        $this->assertStringContainsString('/blog/hazir-ofis-nedir"', $home);
        $this->assertStringNotContainsString('/blog/konyada-sanal-ofis"', $home);
        $this->assertStringContainsString('grid-column:1/-1', $home); // spotlight: ilk kart büyük

        // Yeni yazı yayınlanınca (öne çıkan) ana sayfa kendiliğinden güncellenir.
        $this->actingAs($admin)->post('/panel/icerik', ['website_id' => $this->site->id, 'kind' => 'post', 'title' => 'Hazır ofis taşınma rehberi', 'category' => 'Hazır Ofis', 'body' => 'Taşınma günü için kontrol listesi. '.str_repeat('detay ', 40), 'excerpt' => 'Kontrol listesi.', 'is_featured' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $new = Content::query()->where('slug', 'hazir-ofis-tasinma-rehberi')->firstOrFail();
        $this->assertTrue($new->is_featured);
        $this->actingAs($admin)->put("/panel/icerik/{$new->id}/kaydet-ve-yayinla", ['title' => $new->title, 'body' => $new->body, 'excerpt' => $new->excerpt, 'category' => 'Hazır Ofis', 'is_featured' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringContainsString('/blog/hazir-ofis-tasinma-rehberi"', $this->get('http://localhost/')->assertOk()->getContent());
    }
}
