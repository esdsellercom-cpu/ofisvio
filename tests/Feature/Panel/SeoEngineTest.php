<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Website;
use App\Services\ContentCache;
use App\Services\SeoService;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 15 (SEO Engine) + 21 (Sitemap) — v1: head meta, canonical, robots,
 * JSON-LD, robots.txt, sitemap.xml, denetim, JIT'li ayarlar.
 */
class SeoEngineTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->site = Website::query()->default()->firstOrFail();
    }

    private function publish(string $kind, string $slug, string $title, array $extra = []): Content
    {
        $content = Content::create(array_merge([
            'website_id' => $this->site->id, 'kind' => $kind, 'slug' => $slug, 'title' => $title,
            'excerpt' => 'Bu içerik için elli karakterden uzun, arama sonucunda görünecek bir özet metni.',
            'body' => str_repeat('Gövde paragrafı. ', 30),
        ], $extra));
        $content->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subMinute()])->save();
        app(ContentCache::class)->invalidate($this->site);

        return $content;
    }

    #[Test]
    public function icerik_sayfasi_meta_canonical_robots_ve_json_ld_tasir(): void
    {
        $post = $this->publish('post', 'sanal-ofis', 'Sanal ofis rehberi', ['category' => 'Sanal Ofis']);

        $html = $this->get('/blog/sanal-ofis')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Sanal ofis rehberi — Ofisvio</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Bu içerik için elli', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.config('app.url').'/blog/sanal-ofis">', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="article">', $html);
        $this->assertStringContainsString('"@type":"Article"', $html);
        $this->assertStringContainsString('"headline":"Sanal ofis rehberi"', $html);

        // BreadcrumbList (faz 15): Ana sayfa → Yazılar → Kategori → başlık.
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"position":3,"name":"Sanal Ofis","item":"'.config('app.url').'/blog/kategori/sanal-ofis"', $html);
        $this->assertStringContainsString('"position":4,"name":"Sanal ofis rehberi"', $html);
        $this->assertStringNotContainsString('FAQPage', $html, 'Soru başlığı olmayan içerikte FAQ şeması yok.');

        // FAQPage (faz 17): "## Soru?" başlıkları + cevap paragrafları, en az iki çift.
        $faq = $this->publish('page', 'sss', 'Sık sorulan sorular', ['body' => "## Sanal ofis nedir?\n\nTescil adresi hizmetidir.\n\n## Sözleşme süresi ne kadar?\n\nEn az 12 ay.\n\n## Fiyatlar\n\nKDV hariçtir."]);
        $html = $this->get('/sss')->assertOk()->getContent();
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('"name":"Sanal ofis nedir?","acceptedAnswer":{"@type":"Answer","text":"Tescil adresi hizmetidir."}', $html);
        $this->assertStringNotContainsString('"name":"Fiyatlar"', $html, 'Soru işareti olmayan başlık soru değildir.');
        $this->assertStringContainsString('"position":2,"name":"Sık sorulan sorular"', $html); // sayfa: Ana sayfa → başlık

        // Service düğümleri (faz 17): Ofisvio ana sayfası, vitrin bloğundan.
        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('"@type":"Service","name":"Sanal Ofis"', $home);
        $this->assertStringContainsString('"@type":"WebSite"', $home);
        // Liste sayfalarının başlığı iskelet composer'ında ezilmez (faz 18 düzeltmesi).
        $list = $this->get('/blog')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Yazılar — Ofisvio</title>', $list);
        $this->assertStringContainsString('<link rel="canonical" href="'.config('app.url').'/blog">', $list);

        // Sayfa: WebPage + meta_title öncelikli, noindex sayfa başına.
        $this->publish('page', 'gizli', 'Gizli sayfa', ['meta_title' => 'Özel başlık', 'noindex' => true]);
        $html = $this->get('/gizli')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Özel başlık — Ofisvio</title>', $html);
        $this->assertStringContainsString('content="noindex, nofollow"', $html);
        $this->assertStringContainsString('"@type":"WebPage"', $html);

        // Ana sayfa: WebSite şeması, pazarlama başlığı korunur.
        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('"@type":"WebSite"', $home);
        $this->assertStringContainsString('Şirketinizin adresi bugün hazır olsun — Ofisvio</title>', $home);
    }

    #[Test]
    public function robots_txt_ve_sitemap_siteye_gore_uretilir(): void
    {
        $this->publish('post', 'yazi', 'Yazı');
        $this->publish('page', 'sayfa', 'Sayfa');
        $this->publish('page', 'gizli', 'Gizli', ['noindex' => true]);

        $robots = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();
        $this->assertStringContainsString("Allow: /\nDisallow: /panel", $robots);
        $this->assertStringContainsString('Sitemap: '.config('app.url').'/sitemap.xml', $robots);

        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<loc>'.config('app.url').'/</loc>', $xml);
        $this->assertStringContainsString('<loc>'.config('app.url').'/blog</loc>', $xml);
        $this->assertStringContainsString('<loc>'.config('app.url').'/blog/yazi</loc>', $xml);
        $this->assertStringContainsString('<loc>'.config('app.url').'/sayfa</loc>', $xml);
        $this->assertStringNotContainsString('/gizli</loc>', $xml); // noindex sitemap'te yok
        $this->assertStringNotContainsString('aydinlatma-metni', $xml); // taslak yok

        // Müşteri sitesi kendi alan adıyla.
        $tenant = Website::create(['organization_id' => $this->organization('Acme')->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
        $this->get('http://acme.example/robots.txt')->assertOk()->assertSee('Sitemap: https://acme.example/sitemap.xml');
        $this->get('http://acme.example/sitemap.xml')->assertOk()->assertSee('<loc>https://acme.example/</loc>', false)->assertDontSee('/blog/yazi');
    }

    #[Test]
    public function indeksleme_kapaninca_tum_site_deindekslenir(): void
    {
        $this->publish('post', 'yazi', 'Yazı');
        $this->site->forceFill(['robots_index' => false])->save();
        app(ContentCache::class)->invalidate($this->site);

        $this->get('http://localhost/robots.txt')->assertSee("User-agent: *\nDisallow: /", false)->assertDontSee('Sitemap:');
        $this->get('http://localhost/sitemap.xml')->assertOk()->assertDontSee('<loc>');
        $this->get('http://localhost/blog/yazi')->assertOk()->assertSee('content="noindex, nofollow"', false);
    }

    #[Test]
    public function denetim_bulgulari_deterministik(): void
    {
        $this->publish('post', 'iyi', 'İyi yazı');
        $this->publish('post', 'kotu', str_repeat('Çok uzun başlık ', 6), ['excerpt' => 'kısa', 'body' => "# H1 gövdede\nkısa"]);

        $findings = app(SeoService::class)->audit($this->site);

        $this->assertCount(1, $findings);
        $this->assertSame('kotu', $findings[0]['content']->slug);
        $issues = implode(' | ', $findings[0]['issues']);
        $this->assertStringContainsString('Başlık', $issues);
        $this->assertStringContainsString('Meta açıklama kısa', $issues);
        $this->assertStringContainsString('H1', $issues);
        $this->assertStringContainsString('300 karakterden kısa', $issues);
    }

    #[Test]
    public function ayarlar_jit_ister_denetim_izinle_acilir(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // seo.view/audit/edit var; seo.settings yok
        $finance = $this->staff('finance_admin');

        $this->actingAs($finance)->get('/panel/seo')->assertForbidden();
        $this->actingAs($ops)->get('/panel/seo')->assertOk()->assertSee('Denetim')->assertDontSee('Ayarları değiştirmek için JIT');
        $this->actingAs($ops)->get("/panel/seo/{$this->site->id}/denetim")->assertOk();
        $this->actingAs($ops)->put("/panel/seo/{$this->site->id}/ayarlar", ['seo_locale' => 'tr_TR'])->assertForbidden();

        // system_admin: grant yokken de kapalı; JIT sonrası açık.
        $this->actingAs($admin)->put("/panel/seo/{$this->site->id}/ayarlar", ['seo_locale' => 'tr_TR', 'robots_index' => 0])->assertForbidden();
        $this->assertTrue($this->site->fresh()->robots_index);

        $this->actingAs($admin)->post("/panel/seo/{$this->site->id}/jit", ['reason' => 'staging kopyası indekslenmesin', 'ttl_minutes' => 30])->assertRedirect('/panel/seo');
        $this->actingAs($admin)->put("/panel/seo/{$this->site->id}/ayarlar", [
            'seo_title_suffix' => '| Ofisvio', 'seo_default_description' => 'Varsayılan açıklama.', 'seo_locale' => 'tr_TR', // robots_index gönderilmedi -> kapalı
        ])->assertRedirect('/panel/seo');

        $site = $this->site->fresh();
        $this->assertFalse($site->robots_index);
        $this->assertSame('| Ofisvio', $site->seo_title_suffix);

        // Ayar anında vitrine yansır (önbellek sürüm atladı).
        $this->publish('post', 'yazi', 'Yazı');
        $this->get('http://localhost/blog/yazi')->assertSee('<title>Yazı | Ofisvio</title>', false)->assertSee('noindex, nofollow');
    }
}
