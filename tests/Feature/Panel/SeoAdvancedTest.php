<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Http\Middleware\SiteSeoPolicy;
use App\Jobs\NotifyIndexNow;
use App\Models\Content;
use App\Models\Location;
use App\Models\User;
use App\Models\Website;
use App\Services\ContentCache;
use App\Services\ContentService;
use App\Services\SeoService;
use App\Services\SeoSettingsService;
use App\Site\CookieConsent;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 44 — SEO & GEO gelişmiş ayarlar: sekme izinleri (edit / JIT), meta-robots yönergeleri, OG/Twitter,
 * hreflang, doğrulama meta'ları, geliştirici kodu, robots.txt AI kuralları, sitemap türleri/hariç yollar,
 * llms.txt, Knowledge Graph şeması, URL politikası (yönlendirme, eğik çizgi, küçük harf), otomatik iç
 * bağlantı, teknik denetim, HTML site haritası, IndexNow. Yalın sistem (SeoEngineTest) değişmeden geçer.
 */
class SeoAdvancedTest extends TestCase
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

    /** Ayarı servis üzerinden yazar (panel formu yerine); önbellek sürüm atlar. */
    private function set(array $values): void
    {
        $stored = (array) ($this->site->fresh()->seo_settings ?? []);
        $this->site->forceFill(['seo_settings' => array_replace($stored, $values)])->save();
        $this->site = $this->site->fresh();
        app(ContentCache::class)->invalidate($this->site);
    }

    private function jit(User $user, string $scope, string $tab): void
    {
        $this->actingAs($user)->post("/panel/seo/{$this->site->id}/jit/{$scope}", ['reason' => 'gelişmiş ayar değişikliği testi', 'ttl_minutes' => 30, 'sekme' => $tab])
            ->assertRedirect("/panel/seo/{$this->site->id}/gelismis/{$tab}");
    }

    #[Test]
    public function sekmeler_izne_gore_acilir_kritik_sekmeler_jit_ister_ve_yanlis_rota_404(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // seo.edit var, seo.settings/integrations/geo.settings yok
        $finance = $this->staff('finance_admin');

        $this->actingAs($finance)->get("/panel/seo/{$this->site->id}/gelismis")->assertForbidden();
        $this->actingAs($ops)->get("/panel/seo/{$this->site->id}/gelismis/meta")->assertOk()->assertSee('Meta ayarlarını kaydet');
        $this->actingAs($ops)->get("/panel/seo/{$this->site->id}/gelismis/tarama")->assertOk()->assertSee('Salt okunur')->assertDontSee('Düzenlemek için JIT erişimi iste');
        $this->actingAs($ops)->get("/panel/seo/{$this->site->id}/gelismis/yok")->assertNotFound();

        // Edit sekmesi: ops kaydeder; audit düşer; vitrine yansır.
        $this->actingAs($ops)->put("/panel/seo/{$this->site->id}/gelismis/duzenle/meta", ['meta__title_template' => '{title} | {site}', 'meta__keywords' => 'sanal ofis, coworking', 'meta__og_enabled' => 1, 'meta__twitter_card' => 'summary_large_image', 'meta__twitter_site' => 'ofisvio'])
            ->assertRedirect("/panel/seo/{$this->site->id}/gelismis/meta")->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'seo.settings_updated', 'entity_id' => $this->site->id, 'actor_id' => $ops->id]);
        $this->assertSame('{title} | {site}', app(SeoSettingsService::class)->string($this->site->fresh(), 'meta.title_template'));

        // Kritik sekme edit rotasıyla yazılamaz (404); JIT'siz kritik rota 403; JIT sonrası kaydeder.
        $this->actingAs($ops)->put("/panel/seo/{$this->site->id}/gelismis/duzenle/tarama", ['crawl__sitemap_enabled' => 1])->assertNotFound();
        $this->actingAs($ops)->put("/panel/seo/{$this->site->id}/gelismis/kritik/tarama", ['crawl__sitemap_enabled' => 1])->assertForbidden();
        $this->actingAs($admin)->put("/panel/seo/{$this->site->id}/gelismis/kritik/tarama", ['crawl__sitemap_enabled' => 1])->assertForbidden();
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/gelismis/tarama")->assertOk()->assertSee('Düzenlemek için JIT erişimi iste');
        $this->jit($admin, 'settings', 'tarama');
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/gelismis/tarama")->assertOk()->assertSee('Tarama & indeksleme ayarlarını kaydet');
        $this->actingAs($admin)->put("/panel/seo/{$this->site->id}/gelismis/kritik/tarama", ['crawl__sitemap_enabled' => 1, 'crawl__sitemap_types' => ['pages', 'posts'], 'crawl__ai_crawlers_allowed' => 0, 'crawl__ai_bots' => "GPTBot\nClaudeBot", 'crawl__robots_mode' => 'auto', 'crawl__max_snippet' => -1, 'crawl__max_image_preview' => 'large', 'crawl__max_video_preview' => -1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(app(SeoSettingsService::class)->bool($this->site->fresh(), 'crawl.ai_crawlers_allowed'));

        // Entegrasyon sekmesi ayrı JIT (seo.integrations); geçersiz GA kimliği reddedilir; anahtar otomatik üretilir.
        $this->actingAs($admin)->put("/panel/seo/{$this->site->id}/gelismis/entegrasyon/dogrulama", ['verify__google' => 'abc'])->assertForbidden();
        $this->jit($admin, 'integrations', 'dogrulama');
        $this->actingAs($admin)->from("/panel/seo/{$this->site->id}/gelismis/dogrulama")->put("/panel/seo/{$this->site->id}/gelismis/entegrasyon/dogrulama", ['verify__ga4_id' => 'UA-123'])->assertSessionHasErrors('verify__ga4_id');
        $this->actingAs($admin)->put("/panel/seo/{$this->site->id}/gelismis/entegrasyon/dogrulama", ['verify__google' => 'abc123', 'indexing__indexnow_enabled' => 1, 'indexing__notify_on_publish' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', app(SeoSettingsService::class)->string($this->site->fresh(), 'indexing.indexnow_key'));

        // Varlık sekmesi geo.settings JIT'i (geo_entity kaynağı).
        $this->actingAs($admin)->put("/panel/seo/{$this->site->id}/gelismis/varlik/varlik", ['entity__founder' => 'Ayşe Yılmaz'])->assertForbidden();
        $this->jit($admin, 'entity', 'varlik');
        $this->actingAs($admin)->put("/panel/seo/{$this->site->id}/gelismis/varlik/varlik", ['entity__org_type' => 'Corporation', 'entity__founder' => 'Ayşe Yılmaz', 'entity__wikidata_id' => 'Q12345', 'entity__alternate_names' => "Ofisvio Sanal Ofis\nOfisvio Coworking"])->assertRedirect()->assertSessionHasNoErrors();

        // Geçersiz JSON-LD ve yönlendirme satırı reddedilir (alan bazlı hata).
        $this->actingAs($ops)->from("/panel/seo/{$this->site->id}/gelismis/sema")->put("/panel/seo/{$this->site->id}/gelismis/duzenle/sema", ['schema__enabled' => 1, 'schema__types' => ['WebSite'], 'schema__custom_sitewide' => '{bozuk'])->assertSessionHasErrors('schema__custom_sitewide');
        $this->jit($admin, 'settings', 'url');
        $this->actingAs($admin)->from("/panel/seo/{$this->site->id}/gelismis/url")->put("/panel/seo/{$this->site->id}/gelismis/kritik/url", ['url__canonical_auto' => 1, 'url__www' => 'none', 'url__trailing_slash' => 'strip', 'url__redirects' => [['from' => 'eski', 'to' => '/yeni', 'code' => '301']]])->assertSessionHasErrors('url__redirects.0.from');
    }

    #[Test]
    public function head_meta_robots_og_twitter_hreflang_dogrulama_ve_gelistirici_kodu_basilir(): void
    {
        $post = $this->publish('post', 'sanal-ofis', 'Sanal ofis rehberi');
        $this->set([
            'meta.title_template' => '{title} | {site}', 'meta.keywords' => 'sanal ofis', 'meta.twitter_card' => 'summary_large_image', 'meta.twitter_site' => 'ofisvio',
            'crawl.noarchive' => true, 'crawl.max_snippet' => 160, 'crawl.max_image_preview' => 'standard', 'crawl.index_query_urls' => false, 'crawl.noindex_listings' => true,
            'lang.hreflang_enabled' => true, 'lang.alternates' => [['hreflang' => 'en', 'url' => 'https://en.ofisvio.test']], 'lang.x_default' => '',
            'verify.google' => 'g-token', 'verify.bing' => 'b-token', 'verify.extra_meta' => ['p:domain_verify=pin-token'],
            'verify.ga4_id' => 'G-ABC123', 'dev.head_code' => '<meta name="x-custom-head" content="1">', 'dev.body_start' => '<!-- body-start-marker -->', 'dev.body_end' => '<!-- body-end-marker -->',
            'dev.preconnect' => ['https://cdn.example.com'], 'dev.custom_meta' => ['theme-color=#112233'], 'dev.custom_headers' => ['X-Site-Owner: ofisvio', 'Content-Security-Policy: none'],
        ]);

        $response = $this->get('/blog/sanal-ofis')->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<title>Sanal ofis rehberi | Ofisvio</title>', $html);
        $this->assertStringContainsString('<meta name="keywords" content="sanal ofis">', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow, noarchive, max-snippet:160, max-image-preview:standard">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        $this->assertStringContainsString('<meta name="twitter:site" content="@ofisvio">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="tr-TR" href="'.config('app.url').'/blog/sanal-ofis">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="https://en.ofisvio.test/blog/sanal-ofis">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="'.config('app.url').'/blog/sanal-ofis">', $html);
        $this->assertStringContainsString('<meta name="google-site-verification" content="g-token">', $html);
        $this->assertStringContainsString('<meta name="msvalidate.01" content="b-token">', $html);
        $this->assertStringContainsString('<meta name="p:domain_verify" content="pin-token">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#112233">', $html);
        $this->assertStringContainsString('<link rel="preconnect" href="https://cdn.example.com">', $html);
        // Audit F-06 (KVKK): GA4 tanımlı ama rıza yok → etiket BASILMAZ, rıza bandı görünür; "yalnız zorunlu" → etiket yok, bant yok;
        // "kabul" → etiket basılır. Tercih sunucu çerezinde, düz form POST (tarayıcı depolaması / JS çağrısı yok).
        $this->assertStringNotContainsString('googletagmanager.com/gtag/js', $html);
        $this->assertStringContainsString('data-cookie-bar', $html);
        $this->assertStringContainsString('Yalnız zorunlu', $html);
        $this->assertStringContainsString('name="return" value="/blog/sanal-ofis"', $html);
        $decline = $this->from('/blog/sanal-ofis')->post('/cerez-tercihi', ['choice' => 'essential', 'return' => '/blog/sanal-ofis'])->assertRedirect('/blog/sanal-ofis');
        $decline->assertCookie(CookieConsent::COOKIE, 'essential');
        $declined = $this->withCookie(CookieConsent::COOKIE, 'essential')->get('/blog/sanal-ofis')->assertOk()->getContent();
        $this->assertStringNotContainsString('googletagmanager.com/gtag/js', $declined);
        $this->assertStringNotContainsString('data-cookie-bar', $declined);
        $this->post('/cerez-tercihi', ['choice' => 'all', 'return' => '//evil.example'])->assertRedirect('/'); // açık yönlendirme yok
        $html = $this->withCookie(CookieConsent::COOKIE, 'all')->get('/blog/sanal-ofis')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-cookie-bar', $html);
        $this->assertStringContainsString('googletagmanager.com/gtag/js?id=G-ABC123', $html);
        $this->assertStringContainsString('<meta name="x-custom-head" content="1">', $html);
        $this->assertStringContainsString('<!-- body-start-marker -->', $html);
        $this->assertStringContainsString('<!-- body-end-marker -->', $html);
        $response->assertHeader('X-Site-Owner', 'ofisvio');
        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy')); // korumalı başlık ezilmez
        $this->assertStringContainsString('https://www.googletagmanager.com', (string) $response->headers->get('Content-Security-Policy')); // GA için genişledi

        // Parametreli istek noindex; canonical parametresiz. Liste sayfası (etiket) noindex.
        $this->get('/blog/sanal-ofis?utm_source=x')->assertOk()->assertSee('content="noindex, follow"', false)->assertSee('<link rel="canonical" href="'.config('app.url').'/blog/sanal-ofis">', false);
        $post->forceFill(['tags' => ['rehber']])->save();
        app(ContentCache::class)->invalidate($this->site);
        $this->get('/blog/etiket/rehber')->assertOk()->assertSee('content="noindex, nofollow', false);

        // OG kapatılınca og:* yok; canonical kapatılınca link yok; JSON-LD kapatılınca script yok.
        $this->set(['meta.og_enabled' => false, 'url.canonical_auto' => false, 'schema.enabled' => false, 'meta.twitter_card' => 'none']);
        $html = $this->get('/blog/sanal-ofis')->assertOk()->getContent();
        $this->assertStringNotContainsString('property="og:', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('application/ld+json', $html);
        $this->assertStringNotContainsString('twitter:card', $html);
    }

    #[Test]
    public function robots_sitemap_ve_llms_ayarlara_gore_uretilir(): void
    {
        $this->publish('post', 'yazi', 'Yazı', ['category' => 'Rehber']);
        $this->publish('page', 'sayfa', 'Sayfa');
        $this->publish('page', 'kampanya', 'Kampanya');
        $kadikoy = Location::create(['name' => 'Kadıköy', 'slug' => 'kadikoy', 'city' => 'İstanbul', 'region' => 'Anadolu', 'address_line' => 'Moda Cad. 1', 'is_active' => true, 'is_published' => true]);

        $this->set([
            'crawl.sitemap_types' => ['pages', 'posts', 'locations'], 'crawl.sitemap_exclude' => ['/kampanya', '/blog/kategori/*'],
            'crawl.robots_extra' => "Disallow: /kampanya\nCrawl-delay: 5", 'crawl.ai_bots' => ['GPTBot', 'ClaudeBot'], 'crawl.ai_disallow_paths' => ['/kampanya'],
            'geo.brand_definition' => 'Ofisvio, girişimciler için sanal ofis ve coworking sunar.', 'geo.summary_long' => 'Uzun özet paragrafı.', 'geo.expertise' => ['Sanal ofis', 'Coworking'], 'geo.audience' => 'Girişimciler',
            'geo.faq' => [['q' => 'Sanal ofis nedir?', 'a' => 'Tescil adresi ve posta karşılama hizmetidir.'], ['q' => 'Sözleşme süresi?', 'a' => 'Aylık ya da yıllık.']],
            'geo.priority_urls' => ['/cozumler'], 'geo.resources' => [['title' => 'Fiyat listesi', 'url' => '/fiyatlar']],
        ]);

        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString("Disallow: /user/\nDisallow: /kampanya\nCrawl-delay: 5", $robots);
        $this->assertStringContainsString("User-agent: GPTBot\nDisallow: /kampanya", $robots);
        $this->assertStringContainsString("User-agent: ClaudeBot\nDisallow: /kampanya", $robots);
        $this->assertStringContainsString('Sitemap: '.config('app.url').'/sitemap.xml', $robots);

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/blog/yazi</loc>', $xml);
        $this->assertStringContainsString('/sayfa</loc>', $xml);
        $this->assertStringContainsString('/lokasyon/kadikoy</loc>', $xml);
        $this->assertStringNotContainsString('/kampanya</loc>', $xml); // hariç yol
        $this->assertStringNotContainsString('/blog/kategori/rehber</loc>', $xml); // ön ek kalıbı
        $this->assertStringNotContainsString('/cozumler</loc>', $xml); // tür dışı

        $llms = $this->get('/llms.txt')->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')->getContent();
        $this->assertStringStartsWith("# Ofisvio\n\n> Ofisvio, girişimciler için sanal ofis ve coworking sunar.", $llms);
        $this->assertStringContainsString("## Hakkında\n\nUzun özet paragrafı.\n- Hedef kitle: Girişimciler\n- Uzmanlık alanları: Sanal ofis, Coworking", $llms);
        $this->assertStringContainsString('- [Kadıköy]('.config('app.url').'/lokasyon/kadikoy): Moda Cad. 1, İstanbul', $llms);
        $this->assertStringContainsString('- '.config('app.url').'/cozumler', $llms);
        $this->assertStringContainsString('- [Fiyat listesi]('.config('app.url').'/fiyatlar)', $llms);
        $this->assertStringContainsString('- [Sayfa]('.config('app.url').'/sayfa)', $llms);
        $this->assertStringNotContainsString('/kampanya)', $llms); // AI'ya kapalı yol listelenmez
        $this->assertStringContainsString("**Sanal ofis nedir?**\nTescil adresi ve posta karşılama hizmetidir.", $llms);
        $this->assertStringContainsString('Kapalı yollar (robots.txt ile de bildirilir): /kampanya', $llms);

        // Ana sayfa: Organization Knowledge Graph alanları + GEO SSS FAQPage.
        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('"knowsAbout":["Sanal ofis","Coworking"]', $home);
        $this->assertStringContainsString('"audienceType":"Girişimciler"', $home);
        $this->assertStringContainsString('"@type":"FAQPage"', $home);
        $this->assertStringContainsString('"name":"Sanal ofis nedir?"', $home);

        // AI erişimi kapalı: her bota Disallow: /, llms.txt 404. Sitemap kapalı: 404. Özel robots olduğu gibi.
        $this->set(['crawl.ai_crawlers_allowed' => false, 'crawl.sitemap_enabled' => false]);
        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString("User-agent: GPTBot\nDisallow: /\n", $robots);
        $this->assertStringNotContainsString('Sitemap:', $robots);
        $this->get('/llms.txt')->assertNotFound();
        $this->get('/sitemap.xml')->assertNotFound();
        $this->set(['crawl.robots_mode' => 'custom', 'crawl.robots_custom' => "User-agent: *\nDisallow: /ozel"]);
        $this->get('/robots.txt')->assertOk()->assertSee("User-agent: *\nDisallow: /ozel\n", false)->assertDontSee('GPTBot');
        // Ana bayrak kapalıysa özel metin siteyi indekse sokamaz.
        $this->site->forceFill(['robots_index' => false])->save();
        app(ContentCache::class)->invalidate($this->site);
        $this->get('/robots.txt')->assertSee("User-agent: *\nDisallow: /\n", false);
    }

    #[Test]
    public function url_politikasi_yonlendirme_egik_cizgi_kucuk_harf_ve_panel_dokunulmaz(): void
    {
        $this->publish('page', 'yeni', 'Yeni');
        $this->set(['url.redirects' => [['from' => '/eski', 'to' => '/yeni', 'code' => '301'], ['from' => '/gecici', 'to' => 'https://ornek.test/hedef', 'code' => '302'], ['from' => '/arsiv/*', 'to' => '/blog/*', 'code' => '301']]]);

        $this->get('/eski')->assertRedirect(config('app.url').'/yeni')->assertStatus(301);
        $this->get('/eski?a=1')->assertRedirect(config('app.url').'/yeni?a=1');
        $this->get('/gecici')->assertRedirect('https://ornek.test/hedef')->assertStatus(302);
        $this->get('/arsiv/yazi')->assertRedirect(config('app.url').'/blog/yazi')->assertStatus(301);
        $this->get('/Yeni')->assertRedirect(config('app.url').'/yeni')->assertStatus(301);
        $this->get('/yeni')->assertOk();
        // Sondaki eğik çizgi: test istemcisi URL'yi kırptığı için middleware doğrudan çağrılır.
        $policy = app(SiteSeoPolicy::class);
        $slash = $policy->handle(Request::create(config('app.url').'/yeni/'), fn () => response('ok'));
        $this->assertSame(301, $slash->getStatusCode());
        $this->assertSame(config('app.url').'/yeni', $slash->headers->get('Location'));
        $this->assertSame('ok', $policy->handle(Request::create(config('app.url').'/yeni'), fn () => response('ok'))->getContent());

        // Panel/giriş yolları politikadan etkilenmez; robots_index kapalıyken X-Robots-Tag başlığı basılır.
        $this->get('/login/')->assertOk();
        $this->get('/Login')->assertNotFound();
        $this->set(['url.trailing_slash' => 'keep', 'url.lowercase' => false]);
        $this->assertSame('ok', app(SiteSeoPolicy::class)->handle(Request::create(config('app.url').'/yeni/'), fn () => response('ok'))->getContent());
        $this->get('/Yeni')->assertNotFound();
        $this->site->forceFill(['robots_index' => false])->save();
        app(ContentCache::class)->invalidate($this->site);
        $this->get('/yeni')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        // www / https standardı yalnız alan adı tanımlı sitede.
        $tenant = Website::create(['organization_id' => $this->organization('Acme')->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
        $tenant->forceFill(['seo_settings' => ['url.www' => 'www', 'url.force_https' => true]])->save();
        $this->get('http://acme.example/')->assertRedirect('https://www.acme.example/')->assertStatus(301);
        $this->get('https://www.acme.example/')->assertOk();
    }

    #[Test]
    public function otomatik_ic_baglanti_breadcrumb_ilgili_yazilar_ve_teknik_denetim(): void
    {
        $guide = $this->publish('post', 'rehber', 'Rehber', ['category' => 'Sanal Ofis', 'body' => "## Sanal ofis nedir?\n\nSanal ofis bir adres hizmetidir. Sanal ofis ikinci geçiş.\n\n[Var olan bağlantı sanal ofis](/cozumler) ve `kod sanal ofis` dokunulmaz.\n\n![](/gorsel.png)\n\n[Kırık](/olmayan-sayfa) ve [http](http://ornek.test/x)\n\n".str_repeat('Metin. ', 40)]);
        $this->publish('post', 'diger', 'Rehber', ['category' => 'Sanal Ofis', 'body' => 'Bu yazı [rehbere](/blog/rehber) bağlanır. '.str_repeat('Metin. ', 40)]);
        $this->publish('page', 'yetim', 'Yetim sayfa')->forceFill(['show_in_nav' => false])->save(); // menüde değil, hiçbir gövde bağlamıyor
        app(ContentCache::class)->invalidate($this->site);
        $this->set([
            'links.auto_enabled' => true, 'links.keywords' => [['keyword' => 'sanal ofis', 'url' => '/cozum/sanal-ofis'], ['keyword' => 'adres hizmeti', 'url' => '/blog/rehber']], 'links.max_per_page' => 5,
            'url.redirects' => [['from' => '/a', 'to' => '/b', 'code' => '301'], ['from' => '/b', 'to' => '/c', 'code' => '301'], ['from' => '/d', 'to' => '/d', 'code' => '301']],
        ]);

        $html = $this->get('/blog/rehber')->assertOk()->getContent();
        // Başlık içindeki ve mevcut <a>/<code> içindeki eşleşmeler bağlanmaz; metinde yalnız ilk geçiş.
        $this->assertSame(1, substr_count($html, '<a href="/cozum/sanal-ofis">'));
        $this->assertStringContainsString('<a href="/cozum/sanal-ofis">Sanal ofis</a> bir adres hizmetidir. Sanal ofis ikinci geçiş.', $html);
        $this->assertStringContainsString('<h2>Sanal ofis nedir?</h2>', $html);
        $this->assertStringContainsString('<code>kod sanal ofis</code>', $html);
        $this->assertStringNotContainsString('<a href="/blog/rehber">adres hizmeti</a>', $html); // kendi adresine bağlanmaz
        $this->assertStringContainsString('<img loading="lazy" decoding="async" src="/gorsel.png"', $html);
        $this->assertStringContainsString('aria-label="Gezinti izi"', $html);
        $this->assertStringContainsString('<a href="'.config('app.url').'/blog/kategori/sanal-ofis">Sanal Ofis</a>', $html);
        $this->assertStringContainsString('İlgili yazılar', $html);

        $this->set(['links.auto_enabled' => false, 'links.breadcrumb_enabled' => false, 'links.related_enabled' => false, 'technical.lazy_images' => false]);
        $html = $this->get('/blog/rehber')->assertOk()->getContent();
        $this->assertStringNotContainsString('/cozum/sanal-ofis', $html);
        $this->assertStringNotContainsString('Gezinti izi', $html);
        $this->assertStringNotContainsString('İlgili yazılar', $html);
        $this->assertStringNotContainsString('loading="lazy"', $html);

        $report = app(SeoService::class)->technicalReport($this->site->fresh());
        $duplicateTitles = implode(' | ', $report['duplicate_title']['items']);
        $this->assertStringContainsString('Rehber (/blog/rehber)', $duplicateTitles);
        $this->assertStringContainsString('Rehber (/blog/diger)', $duplicateTitles);
        $this->assertStringContainsString('/olmayan-sayfa', implode(' | ', $report['broken_links']['items']));
        $this->assertStringNotContainsString('/cozumler', implode(' | ', $report['broken_links']['items']));
        $this->assertContains('Yetim sayfa (/yetim)', $report['orphan_pages']['items']);
        $this->assertNotContains('Rehber (/blog/rehber)', $report['orphan_pages']['items']); // diğer yazıdan bağlantı alıyor
        $this->assertStringContainsString('/a → /b → /c', implode(' | ', $report['redirect_chains']['items']));
        $this->assertStringContainsString('/d → kendisine', implode(' | ', $report['redirect_chains']['items']));
        $this->assertStringContainsString('1 görsel', implode(' | ', $report['images_without_alt']['items']));
        $this->assertContains('Rehber (/blog/rehber)', $report['mixed_content']['items']);
        $this->assertSame(1, count($report['duplicate_description']['items'])); // üç içerik aynı özet → tek bulgu

        $this->actingAs($this->staff('operations_admin'))->get("/panel/seo/{$this->site->id}/gelismis/teknik")->assertOk()->assertSee('Teknik denetim')->assertSee('/olmayan-sayfa')->assertSee('Yetim sayfa (/yetim)');

        // HTML site haritası ayara bağlı.
        $this->get('/site-haritasi')->assertOk()->assertSee('Site haritası')->assertSee('/blog/rehber');
        $this->set(['technical.html_sitemap' => false]);
        $this->get('/site-haritasi')->assertNotFound();
    }

    #[Test]
    public function indexnow_anahtar_dosyasi_ve_yayin_olayi_kuyruga_dusurur(): void
    {
        Queue::fake();
        $key = str_repeat('ab', 16);
        $this->set(['indexing.indexnow_enabled' => true, 'indexing.indexnow_key' => $key, 'indexing.notify_on_publish' => true, 'indexing.notify_on_delete' => true, 'indexing.notify_sitemap' => true]);

        $this->get("/{$key}.txt")->assertOk()->assertSee($key);
        $this->get('/'.str_repeat('cd', 16).'.txt')->assertNotFound();

        $editor = $this->staff('system_admin');
        $content = Content::create(['website_id' => $this->site->id, 'kind' => 'post', 'slug' => 'duyuru', 'title' => 'Duyuru', 'body' => str_repeat('Metin. ', 30), 'author_id' => $editor->id]);
        $content->forceFill(['status' => ContentStatus::DRAFT])->save();
        $service = app(ContentService::class);
        $service->transition($editor, $content, ContentStatus::IN_REVIEW);
        $service->transition($editor, $content, ContentStatus::PUBLISHED);

        Queue::assertPushed(NotifyIndexNow::class, fn (NotifyIndexNow $job) => $job->websiteId === $this->site->id && $job->urls === [config('app.url').'/blog/duyuru', config('app.url').'/sitemap.xml']);

        $service->transition($editor, $content->fresh(), ContentStatus::ARCHIVED);
        Queue::assertPushed(NotifyIndexNow::class, 2);

        // Ayar kapalıyken kuyruğa düşmez (arşiv → taslak → inceleme → yayın).
        $this->set(['indexing.indexnow_enabled' => false]);
        $service->transition($editor, $content->fresh(), ContentStatus::DRAFT);
        $service->transition($editor, $content->fresh(), ContentStatus::IN_REVIEW);
        $service->transition($editor, $content->fresh(), ContentStatus::PUBLISHED);
        Queue::assertPushed(NotifyIndexNow::class, 2);
    }
}
