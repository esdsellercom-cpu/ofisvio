<?php

namespace Tests\Feature\Console;

use App\Services\BackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit F-03 — gerçek yedek/geri yükleme provası (BACKUP → DESTROY → RESTORE → VERIFY): dosya tabanlı SQLite ve geçici
 * depolama üzerinde. Şifreli arşiv anahtar olmadan doğrulanamaz; sha256 bozulunca doğrulama düşer; geri yükleme
 * veriyi ve özel dosyayı geri getirir; retention en az N yedek bırakır; doctor yedek yaşını raporlar.
 */
class BackupRestoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ofisvio-bk-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->root.'/db');
        File::ensureDirectoryExists($this->root.'/private/kyc/1');
        File::ensureDirectoryExists($this->root.'/public/media/1');

        // Dosya tabanlı sqlite (bellek içi yedeklenemez) + geçici depolama kökleri.
        config(['database.connections.sqlite.database' => $this->root.'/db/database.sqlite', 'ofisvio.backup.path' => $this->root.'/backups', 'ofisvio.backup.encryption_key' => 'base64:'.base64_encode(random_bytes(32)), 'ofisvio.backup.keep_days' => 30, 'ofisvio.backup.keep_min' => 2]);
        $this->app->useStoragePath($this->root.'/storage');
        File::ensureDirectoryExists($this->root.'/storage/app/private/kyc/1');
        File::ensureDirectoryExists($this->root.'/storage/app/public/media/1');
        File::ensureDirectoryExists($this->root.'/storage/framework');
        touch($this->root.'/db/database.sqlite');
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    #[Test]
    public function yedek_alinir_dogrulanir_yok_edilen_veri_geri_yuklenir(): void
    {
        DB::table('leads')->insert(['kind' => 'quote', 'name' => 'Yedek Kişi', 'email' => 'yedek@example.com', 'status' => 'new', 'consented_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        File::put($this->root.'/storage/app/private/kyc/1/kimlik.pdf', 'PDF-ICERIK');
        File::put($this->root.'/storage/app/public/media/1/kapak.png', 'PNG');

        $service = app(BackupService::class);
        config(['ofisvio.backup.path' => '']); // boş yol (CI .env.example) → varsayılan dizin, mkdir('') hatası yok
        $this->assertStringEndsWith('backups', $service->directory());
        config(['ofisvio.backup.path' => $this->root.'/backups']);
        $created = $service->create();
        $this->assertTrue($created['encrypted']);
        $this->assertFileExists($created['path']);
        $this->assertStringEndsWith('.tar.enc', $created['path']);
        $this->assertArrayHasKey('private/kyc/1/kimlik.pdf', $created['manifest']['files']);
        $this->assertArrayHasKey('public/media/1/kapak.png', $created['manifest']['files']);
        $this->assertStringNotContainsString('PDF-ICERIK', (string) file_get_contents($created['path']), 'şifreli arşivde düz metin yok');

        $verify = $service->verify($created['name']);
        $this->assertTrue($verify['ok'], implode('; ', $verify['problems']));
        $this->assertNotNull($service->lastVerifiedAgeHours());

        // Yanlış anahtar → doğrulanamaz; sha256 bozulur → doğrulama düşer (yedek değişmiş).
        $key = config('ofisvio.backup.encryption_key');
        config(['ofisvio.backup.encryption_key' => 'yanlis-anahtar']);
        $this->assertFalse($service->verify($created['name'])['ok']);
        config(['ofisvio.backup.encryption_key' => $key]);
        $copy = (string) file_get_contents($created['path']);
        File::put($created['path'], $copy.'x');
        $bad = $service->verify($created['name']);
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('sha256', $bad['problems'][0]);
        File::put($created['path'], $copy);

        // DESTROY: veri ve dosya silinir → RESTORE → VERIFY.
        DB::table('leads')->delete();
        File::delete($this->root.'/storage/app/private/kyc/1/kimlik.pdf');
        $this->assertSame(0, DB::table('leads')->count());

        $restored = $service->restore($created['name']);
        $this->assertGreaterThanOrEqual(2, $restored);
        $this->assertSame(1, DB::table('leads')->where('email', 'yedek@example.com')->count());
        $this->assertSame('PDF-ICERIK', (string) file_get_contents($this->root.'/storage/app/private/kyc/1/kimlik.pdf'));

        // Komutlar: --list, --verify, restore --force (doctor kapısı: geliştirme ortamında hata yok → up).
        $this->artisan('ofisvio:backup', ['--list' => true])->expectsOutputToContain($created['name'])->assertSuccessful();
        $this->artisan('ofisvio:backup', ['--verify' => $created['name']])->expectsOutputToContain('Doğrulama: '.$created['name'].' OK')->assertSuccessful();
        $this->artisan('ofisvio:restore', ['name' => $created['name']])->expectsOutputToContain('--force')->assertSuccessful();
        $this->artisan('ofisvio:restore', ['name' => 'ofisvio-19700101-000000-yokyok'])->assertFailed();

        // Retention: ikinci yedek + eskitilmiş manifest → keep_min=2 korur, üçüncü eski yedek silinir.
        $second = $service->create();
        $old = $service->create();
        $manifest = json_decode((string) file_get_contents($this->root.'/backups/'.$old['name'].'.manifest.json'), true);
        $manifest['created_at'] = now()->subDays(60)->toIso8601String();
        File::put($this->root.'/backups/'.$old['name'].'.manifest.json', (string) json_encode($manifest));
        $this->assertSame(1, $service->prune());
        $this->assertFileDoesNotExist($this->root.'/backups/'.$old['name'].'.manifest.json');
        $this->assertFileExists($this->root.'/backups/'.$second['name'].'.manifest.json');

        // Doctor satırı.
        $this->artisan('ofisvio:doctor')->expectsOutputToContain('son doğrulanmış yedek');
    }
}
