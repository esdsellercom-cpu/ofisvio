<?php

namespace Tests\Feature\Console;

use App\Console\Commands\DoctorCommand;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Website;
use App\Security\MalwareScanner;
use App\Security\ScanResult;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Panel\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * ofisvio:doctor — açılış kontrol listesi. Geliştirmede uyarır, üretimde
 * (config app.env=production) aynı maddeler hata olur ve çıkış kodu 1 döner.
 */
class DoctorCommandTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
    }

    /** @return array{0: int, 1: string} çıkış kodu + çıktı */
    private function doctor(string $args = ''): array
    {
        $code = Artisan::call(trim('ofisvio:doctor '.$args));

        return [$code, Artisan::output()];
    }

    #[Test]
    public function gelistirmede_uyarir_uretimde_hata_verir(): void
    {
        // Geliştirme: uyarılar var, çıkış 0.
        [$code, $out] = $this->doctor();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('0 hata', $out);
        $this->assertStringContainsString('KYC tarayıcı', $out);

        // Üretim simülasyonu: aynı maddeler hata; çıkış 1.
        config(['app.env' => 'production', 'app.debug' => true, 'app.url' => 'http://ofisvio.test', 'mail.default' => 'log']);

        [$code, $out] = $this->doctor();
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('✗', $out);
        $this->assertStringContainsString('ortam: production', $out);

        // Üretimde düzgün ayarlarla: kalan hata yalnız tarayıcı/zamanlayıcı.
        config(['app.debug' => false, 'app.url' => 'https://ofisvio.com', 'mail.default' => 'smtp', 'cache.default' => 'file', 'session.driver' => 'database']);
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(string $path): ScanResult
            {
                return ScanResult::clean();
            }
        });
        config(['ofisvio.kyc.scanner' => 'clamav']);
        $this->artisan('content:publish-scheduled')->assertSuccessful(); // kalp atışı

        [$code, $out] = $this->doctor();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('0 hata', $out);
        $this->assertStringContainsString('clamav canlı tarama temiz', $out);
        $this->assertStringContainsString('son çalışma 0 dk önce', $out);
    }

    #[Test]
    public function tarayici_erisilemezse_ve_zamanlayici_sessizse_hata(): void
    {
        config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://ofisvio.com', 'mail.default' => 'smtp', 'ofisvio.kyc.scanner' => 'clamav', 'cache.default' => 'file', 'session.driver' => 'database']);
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(string $path): ScanResult
            {
                return ScanResult::unavailable('bağlantı reddedildi');
            }
        });
        Cache::put(DoctorCommand::HEARTBEAT_KEY, now()->subMinutes(30)->toIso8601String(), now()->addDay());

        $late = Content::create(['website_id' => Website::query()->default()->firstOrFail()->id, 'kind' => 'post', 'slug' => 'gec', 'title' => 'Geç', 'body' => 'x']);
        $late->forceFill(['status' => ContentStatus::SCHEDULED, 'scheduled_for' => now()->subHour()])->save();

        [$code, $out] = $this->doctor();
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('clamd erişilemez: bağlantı reddedildi', $out);
        $this->assertStringContainsString('Son çalışma 30 dk önce', $out);
        $this->assertStringContainsString('1 içerik zamanı geçtiği halde yayınlanmadı', $out);
        $this->assertStringContainsString('2 hata', $out);
    }

    #[Test]
    public function json_ciktisi_makine_okunur(): void
    {
        [$code, $out] = $this->doctor('--json');
        $this->assertSame(0, $code, $out);
        $json = json_decode($out, true);
        $this->assertTrue($json['ok']);
        $this->assertContains('RBAC matrisi', array_column($json['rows'], 'name'));
    }
}
