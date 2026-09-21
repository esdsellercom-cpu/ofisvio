<?php

namespace Tests\Feature\Console;

use App\Console\Commands\DoctorCommand;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Media;
use App\Models\Website;
use App\Security\MalwareScanner;
use App\Security\ScanResult;
use App\Services\BackupService;
use App\Services\LegalDocumentService;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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
        config(['app.debug' => false, 'app.url' => 'https://ofisvio.com', 'mail.default' => 'smtp', 'cache.default' => 'file', 'session.driver' => 'database', 'session.secure' => true, 'session.same_site' => 'lax'] /* audit S-2: üretimde secure çerez şart */);
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(string $path): ScanResult
            {
                return ScanResult::clean();
            }
        });
        config(['ofisvio.kyc.scanner' => 'clamav']);
        $this->artisan('content:publish-scheduled')->assertSuccessful(); // kalp atışı

        // Audit F-07: üretimde KVKK metni sürümü zorunlu — yokken hata, yayınlanınca ok.
        [$code, $out] = $this->doctor();
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('KVKK metni sürümü', $out);
        $site = Website::query()->default()->firstOrFail();
        $kvkk = Content::create(['website_id' => $site->id, 'kind' => 'page', 'slug' => 'kvkk-aydinlatma', 'title' => 'KVKK aydınlatma metni', 'body' => str_repeat('Kişisel verileriniz. ', 30)]);
        app(LegalDocumentService::class)->publishIfChanged(null, $site, 'kvkk', $kvkk, 'ilk sürüm');

        // Audit F-03: üretimde doğrulanmış yedek zorunlu — yokken hata, gerçek yedek alınıp doğrulanınca ok.
        [$code, $out] = $this->doctor();
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('Doğrulanmış yedek yok', $out);
        $this->seedVerifiedBackup();

        [$code, $out] = $this->doctor();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('son doğrulanmış yedek', $out);
        $this->assertStringContainsString('v1', $out);
        $this->assertStringContainsString('0 hata', $out);
        $this->assertStringContainsString('clamav canlı tarama temiz', $out);
        $this->assertStringContainsString('son çalışma 0 dk önce', $out);
    }

    #[Test]
    public function tarayici_erisilemezse_ve_zamanlayici_sessizse_hata(): void
    {
        config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://ofisvio.com', 'mail.default' => 'smtp', 'ofisvio.kyc.scanner' => 'clamav', 'cache.default' => 'file', 'session.driver' => 'database', 'session.secure' => true, 'session.same_site' => 'lax'] /* audit S-2: üretimde secure çerez şart */);
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
        $kvkk = Content::create(['website_id' => $late->website_id, 'kind' => 'page', 'slug' => 'kvkk', 'title' => 'KVKK', 'body' => str_repeat('Metin. ', 20)]);
        app(LegalDocumentService::class)->publishIfChanged(null, Website::query()->default()->firstOrFail(), 'kvkk', $kvkk); // F-07: bu test tarayıcı/zamanlayıcı hatalarını ölçer
        $this->seedVerifiedBackup(); // F-03: aynı nedenle gerçek, doğrulanmış yedek

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
        $this->assertContains('Medya dosyaları', array_column($json['rows'], 'name'), 'medya dosyaları diskte var mı kontrolü raporda olmalı');
    }

    /** Kayıtlı medyanın dosyası diskte yoksa (kırık görsel) doktor uyarır ve örneği söyler. */
    #[Test]
    public function eksik_medya_dosyasi_uyarilir(): void
    {
        Storage::fake('public');
        $website = Website::query()->default()->first() ?? Website::query()->create(['name' => 'Ofisvio', 'domain' => 'localhost', 'is_default' => true]);
        Media::query()->create(['website_id' => $website->id, 'disk' => 'public', 'path' => 'media/1/yok.jpg', 'original_name' => 'yok.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'width' => 1, 'height' => 1, 'checksum_sha256' => str_repeat('a', 64), 'status' => 'approved']);

        [, $out] = $this->doctor('--json');
        $row = collect(json_decode($out, true)['rows'])->firstWhere('name', 'Medya dosyaları');
        $this->assertSame('warn', $row['level'] ?? null, $out);
        $this->assertStringContainsString('yok.jpg', (string) ($row['note'] ?? ''));
    }

    /** Gerçek yedek: dosya tabanlı boş sqlite + geçici depolama; BackupService ile alınır ve doğrulanır (sahte damga yok). */
    private function seedVerifiedBackup(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ofisvio-doctor-bk-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($root.'/storage/app/private');
        File::ensureDirectoryExists($root.'/storage/app/public');
        File::ensureDirectoryExists($root.'/storage/framework');
        (new \PDO('sqlite:'.$root.'/db.sqlite'))->exec('create table doctor_probe (id integer)'); // gerçek, boş olmayan sqlite dosyası
        config(['database.connections.sqlite.database' => $root.'/db.sqlite', 'ofisvio.backup.path' => $root.'/backups']);
        $this->app->useStoragePath($root.'/storage');
        $created = app(BackupService::class)->create(false);
        $this->assertTrue(app(BackupService::class)->verify($created['name'])['ok']);
        config(['database.connections.sqlite.database' => ':memory:']);
    }
}
