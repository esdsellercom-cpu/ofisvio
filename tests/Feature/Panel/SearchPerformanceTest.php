<?php

namespace Tests\Feature\Panel;

use App\Integrations\Google\ServiceAccountAuth;
use App\Models\IntegrationLog;
use App\Models\IntegrationSyncState;
use App\Models\Website;
use App\Seo\HealthCenter;
use App\Services\ContentCache;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 60d — Search Console / Analytics / PageSpeed yalnız Integration Gateway üzerinden (servis hesabı JWT →
 * belirteç), veri günlük özet tablolarına yazılır, panel yalnız saklanan gerçek satırları gösterir; bağlı
 * değilken hiçbir rakam basılmaz. Secret log'a girmez.
 */
class SearchPerformanceTest extends TestCase
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

    /** Gerçek RSA anahtarıyla servis hesabı JSON'u (JWT imzası doğrulanabilsin). */
    private function serviceAccountJson(): string
    {
        // Sabit test anahtarı (tests/Fixtures): yalnız test; Windows'ta openssl.cnf olmadan anahtar üretilemez.
        $pem = (string) file_get_contents(base_path('tests/Fixtures/google-service-account-test.key'));

        return (string) json_encode(['type' => 'service_account', 'client_email' => 'ofisvio-seo@test-project.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']);
    }

    /** @param  array<string, mixed>  $values */
    private function settings(array $values): void
    {
        $stored = (array) ($this->site->fresh()->seo_settings ?? []);
        $this->site->forceFill(['seo_settings' => array_replace($stored, $values)])->save();
        $this->site = $this->site->fresh();
        app(ContentCache::class)->invalidate($this->site);
    }

    #[Test]
    public function bagli_degilken_rakam_basilmaz_ve_senkron_veri_uydurmaz(): void
    {
        $admin = $this->staff('system_admin');
        $finance = $this->staff('finance_admin');
        $base = "/panel/seo/{$this->site->id}";

        $this->actingAs($finance)->get('/panel/seo/search-console')->assertForbidden();
        $this->actingAs($admin)->get('/panel/seo/search-console')->assertRedirect("{$base}/search-console");
        $this->actingAs($admin)->get("{$base}/search-console")->assertOk()->assertSee('env: kapalı')->assertSee('SEARCH_CONSOLE_ENABLED=true')->assertSee('Henüz veri yok')->assertDontSee('Tıklama</span><span class="v">', false);
        $this->actingAs($admin)->get("{$base}/analytics")->assertOk()->assertSee('ANALYTICS_ENABLED=true')->assertSee('Henüz veri yok');

        Http::fake();
        $this->actingAs($admin)->post("{$base}/senkron/search_console")->assertRedirect()->assertSessionHas('status', 'Sağlayıcı yapılandırılmamış; durum kaydedildi.');
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('search_performance_daily')->count());
        $this->assertStringContainsString('Yapılandırma eksik', (string) IntegrationSyncState::query()->where('provider', 'search_console')->value('last_error'));
        $this->actingAs($finance)->post("{$base}/senkron/analytics")->assertForbidden();
        $this->actingAs($admin)->post("{$base}/senkron/bilinmeyen")->assertNotFound();
    }

    #[Test]
    public function servis_hesabi_jwt_ile_gateway_uzerinden_veri_cekilir_ve_panelde_gercek_satirlar_gosterilir(): void
    {
        $admin = $this->staff('system_admin');
        $json = $this->serviceAccountJson();
        config([
            'integrations.providers.search_console.enabled' => true, 'integrations.providers.search_console.secrets.service_account_json' => $json,
            'integrations.providers.analytics.enabled' => true, 'integrations.providers.analytics.secrets.service_account_json' => $json,
            'integrations.providers.google_oauth.enabled' => true,
        ]);
        $this->settings(['integrations.gsc_property' => 'sc-domain:ofisvio.com', 'integrations.ga4_property' => '123456789']);

        $tokenRequests = 0;
        Http::fake(function (Request $request) use (&$tokenRequests) {
            $url = $request->url();

            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                $tokenRequests++;
                $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $request['grant_type']);
                $this->assertCount(3, explode('.', (string) $request['assertion']), 'RS256 JWT: header.claims.signature');

                return Http::response(['access_token' => 'ya29.test-token', 'expires_in' => 3599, 'token_type' => 'Bearer']);
            }

            $this->assertSame('Bearer ya29.test-token', $request->header('Authorization')[0] ?? null);

            if (str_ends_with($url, '/webmasters/v3/sites')) {
                return Http::response(['siteEntry' => [['siteUrl' => 'sc-domain:ofisvio.com', 'permissionLevel' => 'siteFullUser']]]);
            }
            if (str_contains($url, '/sitemaps')) {
                return Http::response(['sitemap' => [['path' => 'https://ofisvio.com/sitemap.xml', 'lastSubmitted' => '2026-09-10T10:00:00Z', 'lastDownloaded' => '2026-09-17T03:00:00Z', 'isPending' => false, 'errors' => 0, 'warnings' => 1, 'contents' => [['type' => 'web', 'submitted' => 40, 'indexed' => 36]]]]]);
            }
            if (str_contains($url, '/searchAnalytics/query')) {
                $dimension = $request['dimensions'][0] ?? 'date';

                return Http::response(['rows' => match ($dimension) {
                    'date' => [['keys' => ['2026-09-15'], 'clicks' => 40, 'impressions' => 900, 'ctr' => 0.044, 'position' => 9.2], ['keys' => ['2026-09-16'], 'clicks' => 55, 'impressions' => 1100, 'ctr' => 0.05, 'position' => 8.7]],
                    'query' => [['keys' => ['konya sanal ofis'], 'clicks' => 30, 'impressions' => 400, 'ctr' => 0.075, 'position' => 4.1], ['keys' => ['sanal ofis fiyatları'], 'clicks' => 6, 'impressions' => 320, 'ctr' => 0.019, 'position' => 12.4]],
                    'page' => [['keys' => ['https://ofisvio.com/cozum/sanal-ofis'], 'clicks' => 50, 'impressions' => 700, 'ctr' => 0.07, 'position' => 5.0]],
                    'country' => [['keys' => ['tur'], 'clicks' => 90, 'impressions' => 1900, 'ctr' => 0.047, 'position' => 8.9]],
                    default => [['keys' => ['MOBILE'], 'clicks' => 70, 'impressions' => 1500, 'ctr' => 0.046, 'position' => 9.0], ['keys' => ['DESKTOP'], 'clicks' => 25, 'impressions' => 500, 'ctr' => 0.05, 'position' => 8.1]],
                }]);
            }
            if (str_contains($url, ':runReport')) {
                $dimension = $request['dimensions'][0]['name'] ?? 'date';
                $this->assertSame('Organic Search', $request['dimensionFilter']['filter']['stringFilter']['value']);
                $row = fn (string $key, array $m) => ['dimensionValues' => [['value' => $key]], 'metricValues' => array_map(fn ($v) => ['value' => (string) $v], $m)];

                return Http::response(['rows' => match ($dimension) {
                    'date' => [$row('20260915', [120, 100, 80, 3, 0.66]), $row('20260916', [140, 120, 95, 5, 0.68])],
                    'landingPagePlusQueryString' => [$row('/cozum/sanal-ofis?utm=x', [90, 80, 60, 6, 0.67]), $row('/lokasyon/konya', [40, 35, 30, 2, 0.75]), $row('/blog/sanal-ofis-nedir', [70, 60, 50, 0, 0.71])],
                    'deviceCategory' => [$row('mobile', [180, 150, 120, 5, 0.66]), $row('desktop', [80, 70, 55, 3, 0.69])],
                    default => [$row('google', [250, 210, 170, 8, 0.68])],
                }]);
            }

            return Http::response([], 404);
        });

        // Senkron (panelden, seo.integration.manage) → satırlar + durum + mülk doğrulaması.
        $this->actingAs($admin)->post("/panel/seo/{$this->site->id}/senkron/search_console")->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/panel/seo/{$this->site->id}/senkron/analytics")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(8, DB::table('search_performance_daily')->count());
        $this->assertSame(2 + 3 + 2 + 1, DB::table('analytics_daily')->count());
        $this->assertSame('/cozum/sanal-ofis', DB::table('analytics_daily')->where('dimension', 'landing_page')->orderBy('sessions', 'desc')->value('key'), 'sorgu dizgisi düşer');
        $state = IntegrationSyncState::query()->where('provider', 'search_console')->firstOrFail();
        $this->assertNull($state->last_error);
        $this->assertSame('sc-domain:ofisvio.com', $state->meta['sites'][0]['siteUrl']);
        $this->assertSame(36, $state->meta['sitemaps'][0]['indexed']);
        $this->assertSame(2, $tokenRequests, 'kapsam başına tek belirteç (search_console 7 API çağrısı, analytics 4): önbellekten yeniden kullanılır');

        // Gateway log'unda secret/gövde yok; sağlayıcılar kayıtlı.
        $this->assertTrue(IntegrationLog::query()->where('provider', 'google_oauth')->where('ok', true)->exists());
        $this->assertTrue(IntegrationLog::query()->where('provider', 'search_console')->where('path', 'like', '%searchAnalytics/query')->exists());
        $this->assertFalse(IntegrationLog::query()->where('path', 'like', '%ya29%')->exists());

        // Panel: gerçek rakamlar, fırsat sorgusu, sitemap durumu, doğrulama rozeti.
        $page = $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/search-console")->assertOk();
        $page->assertSee('servis hesabı mülke erişiyor')->assertSee('95')->assertSee('2.000')->assertSee('konya sanal ofis')->assertSee('sanal ofis fiyatları')->assertSee('İçerik fırsatları')->assertSee('36')->assertSee('TUR')->assertSee('Mobile');
        $analytics = $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/analytics")->assertOk();
        $analytics->assertSee('260')->assertSee('Hizmet sayfaları')->assertSee('Lokasyon sayfaları')->assertSee('Blog')->assertSee('/cozum/sanal-ofis')->assertSee('google');

        // Command Center: entegrasyon bağlı; "bağlı değil" bulgusu yok.
        $report = app(HealthCenter::class)->report($this->site->fresh());
        $this->assertTrue($report['integrations']['search_console']['connected']);
        $this->assertNull(collect($report['issues'])->firstWhere('title', 'Search Console bağlı değil'));

        // JWT imzası servis hesabının açık anahtarıyla doğrulanır.
        $account = app(ServiceAccountAuth::class)->account('search_console');
        $jwt = app(ServiceAccountAuth::class)->assertion($account, 'https://www.googleapis.com/auth/webmasters.readonly', 1_700_000_000);
        [$h, $c, $s] = explode('.', $jwt);
        $claims = json_decode(base64_decode(strtr($c, '-_', '+/')), true);
        $this->assertSame($account['client_email'], $claims['iss']);
        $this->assertSame(1_700_003_600, $claims['exp']);
        $public = openssl_pkey_get_details(openssl_pkey_get_private($account['private_key']))['key'];
        $this->assertSame(1, openssl_verify($h.'.'.$c, base64_decode(strtr($s, '-_', '+/')), $public, OPENSSL_ALGO_SHA256));
    }
}
