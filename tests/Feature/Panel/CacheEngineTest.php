<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\User;
use App\Models\Website;
use App\Services\ContentCache;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
