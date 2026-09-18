<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\User;
use App\Models\Website;
use App\Services\ContentCache;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 12 (Cache Engine) + 13 (tenant izolasyonu) + 14 (gözlem) — v1.
 */
class CacheEngineTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Website $default;

    private Website $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->default = Website::query()->default()->firstOrFail();
        $this->tenant = Website::create(['organization_id' => $this->organization('Acme')->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
    }

    private function publish(Website $website, string $slug, string $title): Content
    {
        $content = Content::create(['website_id' => $website->id, 'kind' => 'post', 'slug' => $slug, 'title' => $title, 'body' => 'Gövde']);
        $content->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subMinute()])->save();
        app(ContentCache::class)->invalidate($website); // servis dışı yazım: elle geçersiz kıl

        return $content;
    }

    private function queries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    #[Test]
    public function ikinci_istek_icerik_sorgusu_yapmaz_ve_isabet_sayar(): void
    {
        $this->publish($this->default, 'ilk', 'İlk yazı');

        $first = $this->queries(fn () => $this->get('/blog')->assertOk()->assertSee('İlk yazı'));
        $second = $this->queries(fn () => $this->get('/blog')->assertOk()->assertSee('İlk yazı'));

        $this->assertLessThan($first, $second, "İkinci istek ({$second}) ilkinden ({$first}) az sorgu yapmalı.");

        $stats = app(ContentCache::class)->stats($this->default);
        $this->assertGreaterThan(0, $stats['hits']);
        $this->assertGreaterThan(0, $stats['misses']);
    }

    #[Test]
    public function serilestiren_surucude_de_calisir_nesne_saklamaz(): void
    {
        // Regresyon: array store nesneleri olduğu gibi tutar; file/Redis serileştirir
        // ve Laravel 13 cache'ten nesne unserialize ETMEZ (serializable_classes=false).
        // Eloquent Collection önbelleklenince __PHP_Incomplete_Class ile 500 alınıyordu
        // (CI Redis'te yakaladı). Ham öznitelik dizisi saklanır, hydrate edilir.
        $store = Cache::store('file');
        $store->flush();
        $this->app->instance(ContentCache::class, new ContentCache($store));

        try {
            $this->publish($this->default, 'dosya', 'Dosya önbelleği');

            $this->get('/blog')->assertOk()->assertSee('Dosya önbelleği');
            $this->get('/blog')->assertOk()->assertSee('Dosya önbelleği');   // önbellekten
            $this->get('/blog/dosya')->assertOk()->assertSee('Gövde');
            $this->get('/blog/dosya')->assertOk()->assertSee('Gövde');       // önbellekten

            $raw = (string) file_get_contents(collect(glob(storage_path('framework/cache/data/*/*/*')))->first());
            $this->assertStringNotContainsString('O:', $raw, 'Önbellekte serileştirilmiş nesne olmamalı.');
        } finally {
            $store->flush();
        }
    }

    #[Test]
    public function bozuk_veya_eski_bicimli_onbellek_kaydi_sayfayi_dusurmez(): void
    {
        // Regresyon: geliştirme DB'sinde eski kodun yazdığı serileştirilmiş nesne
        // (__PHP_Incomplete_Class) yeni kodun hydrate() çağrısını 500 ile düşürdü.
        // Dizi olmayan değer ıskalama sayılır ve üzerine yazılır; anahtar şema
        // sürümü taşır (eski anahtar zaten okunmaz).
        $this->publish($this->default, 'saglam', 'Sağlam yazı');
        $cache = app(ContentCache::class);

        $this->assertStringContainsString(':s'.ContentCache::SCHEMA.':', $cache->key($this->default, 'posts:3'));

        // Ana sayfa yazı bölümü en fazla 6 yazı okur (sayfa kurucu: adet bölüm ayarından).
        // Ana sayfa yazı bölümü (faz 58): öne çıkanlar + en yeni 12; blog listesi 50.
        foreach (['posts:12', 'posts:featured:12', 'posts:50', 'pages'] as $name) {
            Cache::put($cache->key($this->default, $name), new \__PHP_Incomplete_Class, 600);
        }

        $this->get('/')->assertOk()->assertSee('Sağlam yazı');
        $this->get('/blog')->assertOk()->assertSee('Sağlam yazı');
        $this->assertIsArray(Cache::get($cache->key($this->default, 'posts:12')), 'Bozuk kayıt üzerine yazılmalı.');
    }

    #[Test]
    public function yayin_akisi_onbellegi_kendiliginden_gecersiz_kilar(): void
    {
        $admin = $this->staff('system_admin');
        $this->get('/blog')->assertOk()->assertDontSee('Yeni yazı X'); // önbelleğe boş liste girdi

        $this->actingAs($admin)->post('/panel/icerik', ['website_id' => $this->default->id, 'kind' => 'post', 'title' => 'Yeni yazı X', 'body' => 'Gövde']);
        $content = Content::where('slug', 'yeni-yazi-x')->firstOrFail();
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/incelemeye-gonder");
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/yayinla");

        // Bayat liste yok: yayın anında görünür.
        $this->get('http://localhost/blog')->assertOk()->assertSee('Yeni yazı X');

        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/yayindan-kaldir");
        $this->get('http://localhost/blog')->assertOk()->assertDontSee('Yeni yazı X');
    }

    #[Test]
    public function gecersizleme_yalnizca_o_siteyi_etkiler(): void
    {
        $cache = app(ContentCache::class);
        $this->publish($this->default, 'a', 'A');
        $this->publish($this->tenant, 'b', 'B');

        $this->get('http://localhost/blog')->assertSee('A');
        $this->get('http://acme.example/blog')->assertSee('B');

        $vDefault = $cache->version($this->default);
        $vTenant = $cache->version($this->tenant);
        $keyTenant = $cache->key($this->tenant, 'posts:50');

        $cache->invalidate($this->default);

        $this->assertSame($vDefault + 1, $cache->version($this->default));
        $this->assertSame($vTenant, $cache->version($this->tenant), 'Diğer sitenin sürümü değişmemeli.');
        $this->assertSame($keyTenant, $cache->key($this->tenant, 'posts:50'), 'Diğer sitenin anahtarları aynı kalmalı.');
        $this->assertStringStartsWith("site:{$this->tenant->id}:", $keyTenant);
    }

    #[Test]
    public function misafire_public_onbellek_basligi_ve_304_oturum_acmisa_no_store(): void
    {
        $this->publish($this->default, 'a', 'A');

        $response = $this->get('/blog')->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=60', $cacheControl);
        $this->assertStringContainsString('s-maxage=300', $cacheControl);
        $etag = $response->headers->get('ETag');
        $this->assertNotNull($etag);

        $this->withHeaders(['If-None-Match' => $etag])->get('/blog')->assertStatus(304);

        // Oturum açmış kullanıcı: kişiye özel, paylaşılmaz.
        $user = User::factory()->create();
        $this->actingAs($user)->get('/blog')->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        // 404 ve POST'a dokunulmaz.
        $this->get('/blog/yok')->assertNotFound()->assertHeaderMissing('ETag');
    }

    #[Test]
    public function purge_jit_ister_warm_istemez(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // cache.view var; warm/invalidate yok

        $this->actingAs($ops)->get('/panel/onbellek')->assertOk()->assertDontSee('JIT iste');
        $this->actingAs($ops)->post("/panel/onbellek/{$this->default->id}/isit")->assertForbidden();

        $this->actingAs($admin)->get('/panel/onbellek')->assertOk()->assertSee('JIT iste');
        $this->actingAs($admin)->post("/panel/onbellek/{$this->default->id}/isit")->assertRedirect('/panel/onbellek');

        // Grant olmadan purge kapalı.
        $before = app(ContentCache::class)->version($this->default);
        $this->actingAs($admin)->post("/panel/onbellek/{$this->default->id}/gecersiz-kil")->assertForbidden();
        $this->actingAs($admin)->post('/panel/onbellek/tumu/bosalt')->assertForbidden();
        $this->assertSame($before, app(ContentCache::class)->version($this->default));

        // JIT aç -> purge açılır, yalnızca o site için.
        $this->actingAs($admin)->post("/panel/onbellek/{$this->default->id}/jit", ['reason' => 'yanlış fiyat yayınlandı, düzeltildi', 'ttl_minutes' => 30])
            ->assertRedirect('/panel/onbellek');
        $this->actingAs($admin)->post("/panel/onbellek/{$this->default->id}/gecersiz-kil")->assertRedirect('/panel/onbellek');
        $this->assertSame($before + 1, app(ContentCache::class)->version($this->default));
        $this->actingAs($admin)->post("/panel/onbellek/{$this->tenant->id}/gecersiz-kil")->assertForbidden();
        $this->actingAs($admin)->post('/panel/onbellek/tumu/bosalt')->assertForbidden();

        // Global purge ayrı grant (kaynak 0).
        $this->actingAs($admin)->post('/panel/onbellek/0/jit', ['reason' => 'sürüm geçişi sonrası tam temizlik', 'ttl_minutes' => 15])->assertRedirect();
        $this->actingAs($admin)->post('/panel/onbellek/tumu/bosalt')->assertRedirect('/panel/onbellek');
    }

    #[Test]
    public function anahtar_ozeti_icerik_gostermez_ve_ayarlar_jit_ister(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // cache.view var; inspect/settings yok

        $this->get('/blog')->assertOk(); // posts:50 + kategoriler üretilir
        $this->actingAs($admin)->post("/panel/onbellek/{$this->default->id}/isit")->assertRedirect();

        $this->actingAs($ops)->get("/panel/onbellek/{$this->default->id}/anahtarlar")->assertForbidden();
        $this->actingAs($ops)->get('/panel/onbellek')->assertOk()->assertDontSee('Anahtarlar')->assertDontSee('Ayar erişimi aç');

        $html = $this->actingAs($admin)->get("/panel/onbellek/{$this->default->id}/anahtarlar")->assertOk()->getContent();
        $this->assertStringContainsString('posts:50', $html);
        $this->assertStringContainsString('pages', $html);
        $this->assertStringContainsString('array', $html);
        $this->assertStringNotContainsString('Sözleşme', $html, 'Anahtar içeriği (gövde) basılmaz.');

        // Ayarlar: grant yokken 403; JIT (izin=settings) sonrası kaydedilir, HTTP başlıkları ve TTL değişir.
        $this->actingAs($admin)->put("/panel/onbellek/{$this->default->id}/ayarlar", ['cache_ttl_seconds' => 120, 'http_max_age' => 15, 'http_s_maxage' => 900])->assertForbidden();
        $this->actingAs($admin)->post("/panel/onbellek/{$this->default->id}/jit", ['izin' => 'settings', 'reason' => 'kampanya döneminde daha kısa proxy süresi', 'ttl_minutes' => 30])->assertRedirect('/panel/onbellek');
        $this->actingAs($admin)->get('/panel/onbellek')->assertOk()->assertSee('name="cache_ttl_seconds"', false);
        $this->actingAs($admin)->from('/panel/onbellek')->put("/panel/onbellek/{$this->default->id}/ayarlar", ['cache_ttl_seconds' => 5])->assertSessionHasErrors('cache_ttl_seconds');
        $this->actingAs($admin)->put("/panel/onbellek/{$this->default->id}/ayarlar", ['cache_ttl_seconds' => 120, 'http_max_age' => 15, 'http_s_maxage' => 900])->assertRedirect('/panel/onbellek');

        $this->default->refresh();
        $this->assertSame(120, app(ContentCache::class)->ttl($this->default));
        auth()->logout(); // misafir başlıkları
        $cc = (string) $this->get('http://localhost/blog')->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=15', $cc);
        $this->assertStringContainsString('s-maxage=900', $cc);

        // Başka site (tenant) kod varsayılanında kalır; settings grant'i yalnız o siteye.
        $this->assertStringContainsString('max-age=60', (string) $this->get('http://acme.example/')->assertOk()->headers->get('Cache-Control'));
        $this->actingAs($admin)->put("/panel/onbellek/{$this->tenant->id}/ayarlar", ['cache_ttl_seconds' => 120])->assertForbidden();

        // Boş = varsayılana dönüş.
        $this->actingAs($admin)->put("/panel/onbellek/{$this->default->id}/ayarlar", [])->assertRedirect();
        $this->assertSame(ContentCache::TTL_SECONDS, app(ContentCache::class)->ttl($this->default->fresh()));
    }
}
