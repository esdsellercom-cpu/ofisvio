<?php

namespace Tests\Feature\Panel;

use App\Models\CacheEvent;
use App\Models\IntegrationLog;
use App\Models\PerformanceSample;
use App\Models\Service;
use App\Models\SlowQuery;
use App\Models\Website;
use App\Models\WebVitalsSample;
use App\Services\ContentCache;
use App\Services\WebVitalsService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 60f — Performance Command Center: istek profili (örnekleme, sorgu sayısı, TTFB, yavaş sorgu), önbellek
 * geçersizleme kaskadı (CMS değişikliği → sürüm atlar + olay izi), anahtar bağlamı (kurulum kimliği + dil),
 * dashboard/sorgu/yavaş sorgu/Redis/HTTP cache/asset/CWV/denetim sayfaları gerçek veriden; CWV ölçümü sağlayıcı
 * kapalıyken veri uydurmaz.
 */
class PerformanceCenterTest extends TestCase
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

    #[Test]
    public function istek_profili_orneklenir_yavas_sorgu_yakalanir_ve_ziyaretciyi_bozmaz(): void
    {
        // Örnekleme kapalı (phpunit.xml): hiçbir istek yazmaz.
        $this->get('http://localhost/')->assertOk();
        $this->assertSame(0, PerformanceSample::count());

        config(['ofisvio.performance.sample_rate' => 1.0, 'ofisvio.performance.slow_query_ms' => 0]);
        $this->get('http://localhost/')->assertOk();
        $sample = PerformanceSample::query()->latest('id')->firstOrFail();
        $this->assertSame('site', $sample->kind);
        $this->assertSame('site.home', $sample->route);
        $this->assertSame('/', $sample->path);
        $this->assertSame(200, $sample->status);
        $this->assertGreaterThan(0, $sample->query_count);
        $this->assertGreaterThan(1000, $sample->response_bytes);
        $this->assertFalse($sample->authenticated);
        $this->assertGreaterThan(0, SlowQuery::count(), 'eşik 0 ms: her sorgu yavaş sayılır → kayıt');
        $slow = SlowQuery::query()->firstOrFail();
        $this->assertStringNotContainsString("'", (string) $slow->sql, 'SQL bağlamsız (bindings yok)');
        $this->assertSame(40, strlen($slow->sql_hash));

        // Panel isteği panel türünde ve oturumlu.
        $admin = $this->staff('system_admin');
        $this->actingAs($admin)->get('/panel/operasyon')->assertOk();
        $panel = PerformanceSample::query()->where('kind', 'panel')->latest('id')->firstOrFail();
        $this->assertTrue($panel->authenticated);
        $this->assertStringStartsWith('/panel', $panel->path);
    }

    #[Test]
    public function onbellek_kaskadi_cms_degisikliginde_surum_atlatir_ve_olay_izi_birakir(): void
    {
        $cache = app(ContentCache::class);
        $before = $cache->version($this->site);
        $service = Service::query()->where('slug', 'sanal-ofis')->firstOrFail();
        $service->summary = 'Güncellenmiş özet — kaskad testi.';
        $service->save();

        $this->assertGreaterThan($before, $cache->version($this->site->fresh()));
        $event = CacheEvent::query()->where('trigger', 'service.saved')->where('entity_id', $service->id)->latest('id')->firstOrFail();
        $this->assertSame($this->site->id, $event->website_id);
        $this->assertContains('Sayfa önbelleği (sürüm atladı)', $event->steps);
        $this->assertStringContainsString('şema', mb_strtolower(implode(' ', $event->steps)));

        // Anahtar bağlamı: kurulum kimliği ve dil anahtarda; sürüm site başına.
        $key = $cache->key($this->site->fresh(), 'posts:12');
        $this->assertStringContainsString(':i'.substr(sha1((string) config('ofisvio.installation_id')), 0, 8).':'.config('app.locale').':posts:12', $key);
        config(['ofisvio.installation_id' => 'baska-kurulum']);
        $this->assertNotSame($key, $cache->key($this->site->fresh(), 'posts:12'), 'farklı kurulum farklı anahtar');
    }

    #[Test]
    public function performans_merkezi_sayfalari_gercek_veriyle_acilir_ve_cwv_olcumu_saglayici_kapaliyken_veri_uydurmaz(): void
    {
        $admin = $this->staff('system_admin');
        $finance = $this->staff('finance_admin');
        PerformanceSample::query()->create(['kind' => 'site', 'route' => 'site.home', 'path' => '/', 'method' => 'GET', 'status' => 200, 'duration_ms' => 120, 'query_count' => 9, 'query_ms' => 30, 'memory_mb' => 12.5, 'response_bytes' => 40000, 'cache_hit' => false, 'authenticated' => false, 'created_at' => now()]);
        PerformanceSample::query()->create(['kind' => 'site', 'route' => 'site.home', 'path' => '/', 'method' => 'GET', 'status' => 304, 'duration_ms' => 40, 'query_count' => 5, 'query_ms' => 10, 'memory_mb' => 10.0, 'response_bytes' => 0, 'cache_hit' => true, 'authenticated' => false, 'created_at' => now()]);
        SlowQuery::query()->create(['route' => 'site.home', 'sql_hash' => sha1('select * from contents where website_id = ?'), 'sql' => 'select * from contents where website_id = ?', 'duration_ms' => 240, 'connection' => 'sqlite', 'created_at' => now()]);

        $this->actingAs($finance)->get('/panel/performans/merkez')->assertForbidden();
        $this->actingAs($admin)->get('/panel/performans/merkez')->assertOk()->assertSee('Performans dashboard')->assertSee('Vitrin TTFB p50 / p95')->assertSee('40 / 120 ms')->assertSee('%50')->assertSee('DB gecikmesi');
        $this->actingAs($admin)->get('/panel/performans/sorgular')->assertOk()->assertSee('site.home')->assertSee('7');
        $this->actingAs($admin)->get('/panel/performans/yavas-sorgular')->assertOk()->assertSee('select * from contents where website_id = ?')->assertSee('240 ms');
        $this->actingAs($admin)->get('/panel/performans/redis')->assertOk()->assertSee('Redis yapılandırılmamış');
        $this->actingAs($admin)->get('/panel/performans/http-onbellek')->assertOk()->assertSee('Geçersizleme kaskadı')->assertSee('max-age');
        $this->actingAs($admin)->get('/panel/performans/varliklar')->assertOk()->assertSee('css/ofisvio.css')->assertSee('gzip');
        $this->actingAs($admin)->get('/panel/performans/denetim')->assertOk()->assertSee('Config önbelleği')->assertSee('OPcache')->assertSee('APP_DEBUG');

        // CWV: sağlayıcı kapalı → ölçüm yok, hata mesajı; dış istek yok (sızan istek test hatası olur).
        Http::preventStrayRequests();
        $this->actingAs($admin)->get('/panel/performans/web-vitals')->assertOk()->assertSee('Ölçüm yok')->assertSee('kapalı (PAGESPEED_ENABLED)');
        $this->actingAs($admin)->from('/panel/performans/web-vitals')->post('/panel/performans/web-vitals/olc')->assertSessionHasErrors('vitals');
        $this->assertSame(0, WebVitalsSample::count());

        // Sağlayıcı açık: PageSpeed yanıtı Gateway üzerinden, lab + alan verisi saklanır, dereceler doğru.
        config(['integrations.providers.pagespeed.enabled' => true]);
        Http::fake(['www.googleapis.com/pagespeedonline/*' => Http::response([
            'lighthouseResult' => ['categories' => ['performance' => ['score' => 0.91]], 'audits' => ['largest-contentful-paint' => ['numericValue' => 2100.4], 'cumulative-layout-shift' => ['numericValue' => 0.02], 'interaction-to-next-paint' => ['numericValue' => 150], 'first-contentful-paint' => ['numericValue' => 900], 'server-response-time' => ['numericValue' => 210], 'speed-index' => ['numericValue' => 1800]]],
            'loadingExperience' => ['metrics' => ['LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 2900], 'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 12], 'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 180]]],
        ])]);
        $this->actingAs($admin)->post('/panel/performans/web-vitals/olc')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertGreaterThanOrEqual(2, WebVitalsSample::count());
        $sample = WebVitalsSample::query()->where('path', '/')->where('strategy', 'mobile')->firstOrFail();
        $this->assertSame(91, $sample->score);
        $this->assertEquals(2900, $sample->field['lcp_ms']);
        $this->assertEqualsWithDelta(0.12, $sample->field['cls'], 0.001);
        $grades = app(WebVitalsService::class)->grades($sample);
        $this->assertSame(['lcp_ms' => 'needs', 'inp_ms' => 'good', 'cls' => 'needs', 'fcp_ms' => 'good', 'ttfb_ms' => 'good'], $grades);
        $this->actingAs($admin)->get('/panel/performans/web-vitals')->assertOk()->assertSee('2900 (alan)')->assertSee('91');
        $this->actingAs($admin)->get('/panel/performans/merkez')->assertOk()->assertSee('Core Web Vitals (son ölçüm)')->assertSee('mobile');
        $this->assertFalse(IntegrationLog::query()->where('path', 'like', '%key=%')->exists(), 'sorgu dizgisi (API anahtarı) loglanmaz');

        // Menü.
        $this->actingAs($admin)->get('/panel/operasyon')->assertOk()->assertSee('Performance Dashboard')->assertSee('Yavaş sorgular')->assertSee('Core Web Vitals');
    }
}
