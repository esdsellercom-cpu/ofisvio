<?php

namespace Tests\Feature\Install;

use App\Install\EnvWriter;
use App\Install\InstallGate;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Web kurulum sihirbazının uçtan uca provası (faz 62): anahtar → gereksinimler → veritabanı → site → tablolar →
 * referans veri → yönetici → kapanış. Kapanıştan sonra uç 404'tür ve anahtar dosyası silinmiştir.
 *
 * .env yazımı geçici dosyaya yönlendirilir (gerçek .env'e dokunulmaz); storage yolu da geçici dizindir.
 */
class InstallWizardFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    private string $envFile;

    private string $realEnvFingerprint;

    protected function setUp(): void
    {
        parent::setUp();

        $base = storage_path('framework/testing/wizard-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($base.'/app/install');
        File::ensureDirectoryExists($base.'/framework/sessions');
        File::ensureDirectoryExists($base.'/framework/views');
        File::ensureDirectoryExists($base.'/logs');
        $this->storage = $base;
        $this->envFile = $base.'/.env.kurulum';
        File::copy(base_path('.env.example'), $this->envFile);
        config(['ofisvio.install.env_file' => $this->envFile]);
        $this->realEnvFingerprint = $this->fingerprintRealEnv();
        $this->app->useStoragePath($base);
    }

    /** Gerçek .env dosyasının parmak izi — sihirbazın ona dokunmadığını kanıtlar. */
    private function fingerprintRealEnv(): string
    {
        return is_file(base_path('.env')) ? (string) md5_file(base_path('.env')) : 'yok';
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    #[Test]
    public function sihirbaz_kurulumu_tamamlar_ve_ucu_kalici_olarak_kapatir(): void
    {
        $token = str_repeat('K', 40);
        File::put(storage_path(InstallGate::CHALLENGE), $token."\n");
        $sqlite = $this->storage.'/kurulum.sqlite';

        $this->post('/install', ['token' => $token])->assertRedirect(route('install.requirements'));
        $this->get('/install/gereksinimler')->assertOk()->assertSee('PHP uzantıları');

        // Veritabanı: bilgiler önce denenir, sonra .env'e yazılır.
        $this->post('/install/veritabani', ['connection' => 'sqlite', 'database' => $sqlite])
            ->assertRedirect(route('install.site'));
        $this->assertStringContainsString('DB_DATABASE='.$sqlite, (string) file_get_contents($this->envFile));
        $this->assertSame($this->realEnvFingerprint, $this->fingerprintRealEnv()); // gerçek .env'e dokunulmadı

        // Site: üretim değerleri ve https çerezi .env'e yazılır.
        $this->post('/install/site', [
            'url' => 'https://ornek.test',
            'timezone' => 'Europe/Istanbul',
            'trusted_proxies' => '*',
            'installation_id' => 'ornek-kurulum',
            'kyc_scanner' => 'disabled',
            'mail_mailer' => 'log',
        ])->assertRedirect(route('install.setup'));

        $env = (string) file_get_contents($this->envFile);
        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env);
        $this->assertStringContainsString('APP_URL=https://ornek.test', $env);
        // Güvenli çerez bayrağı burada AÇILMAZ (istek http): açılsaydı operatörün oturumu bir sonraki adımda kopardı.
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=false', $env);
        // Paylaşımlı hostingde clamd yoktur: sistem açılır ama taranmamış belge kabul edilmez (üretimde 'none' yasak).
        $this->assertStringContainsString('KYC_SCANNER=disabled', $env);

        // Ağır adımlar ayrı isteklerde.
        $this->post('/install/kurulum/tablolar')->assertRedirect(route('install.setup'));
        $this->post('/install/kurulum/veri')->assertRedirect(route('install.setup'));
        $this->get('/install/kurulum')->assertOk()->assertSee('Yönetici hesabına geç');

        // Yönetici yalnız HTTPS üzerinden alınır: şifresiz bağlantıda hesap açılmaz, operatör anlaşılır hata alır.
        $this->post('http://localhost/install/yonetici', ['name' => 'Operatör', 'email' => 'kurulum@ornek.test', 'password' => 'Kurulum.Prova.2026!x', 'password_confirmation' => 'Kurulum.Prova.2026!x'])
            ->assertRedirect()
            ->assertSessionHasErrors('domain');
        $this->assertDatabaseMissing('users', ['email' => 'kurulum@ornek.test']);

        $this->get('https://ornek.test/install/yonetici')->assertOk()->assertSee('Ad soyad');
        $this->post('https://ornek.test/install/yonetici', [
            'name' => 'Operatör',
            'email' => 'kurulum@ornek.test',
            'password' => 'Kurulum.Prova.2026!x',
            'password_confirmation' => 'Kurulum.Prova.2026!x',
        ])->assertRedirect(route('install.finish'));

        // Yönetici adımı HTTPS zorunlu olduğu için güvenli çerez bayrağı burada kesinleşir.
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', (string) file_get_contents($this->envFile));

        $admin = User::query()->where('email', 'kurulum@ornek.test')->first();
        $this->assertNotNull($admin);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(UserRole::query()->where('user_id', $admin->id)
            ->whereHas('role', fn ($q) => $q->where('name', 'super_admin'))
            ->where('status', 'active')->exists());

        // Kapanış: kilit yazılır, anahtar silinir, özet AYNI yanıtta basılır (yönlendirme 404 olurdu).
        $this->post('https://ornek.test/install/bitir')->assertOk()->assertSee('Kurulum tamamlandı');

        $this->assertFileExists(storage_path(InstallGate::LOCK));
        $this->assertFileDoesNotExist(storage_path(InstallGate::CHALLENGE));
        $this->assertTrue(AuditLog::query()->where('action', 'install.completed')->exists());

        $this->get('https://ornek.test/install')->assertNotFound();
        $this->get('https://ornek.test/install/yonetici')->assertNotFound();
    }

    #[Test]
    public function zayif_sifre_ve_dolu_veritabani_reddedilir(): void
    {
        $token = str_repeat('L', 40);
        File::put(storage_path(InstallGate::CHALLENGE), $token."\n");
        $this->post('/install', ['token' => $token]);

        $this->post('/install/veritabani', ['connection' => 'sqlite', 'database' => $this->storage.'/x.sqlite']);
        $this->post('/install/site', ['url' => 'https://ornek.test', 'timezone' => 'Europe/Istanbul', 'kyc_scanner' => 'disabled', 'mail_mailer' => 'log']);
        $this->post('/install/kurulum/tablolar');
        $this->post('/install/kurulum/veri');

        $this->post('https://ornek.test/install/yonetici', [
            'name' => 'Operatör',
            'email' => 'zayif@ornek.test',
            'password' => 'kisa123',
            'password_confirmation' => 'kisa123',
        ])->assertRedirect();
        $this->assertDatabaseMissing('users', ['email' => 'zayif@ornek.test']);

        // Dolu veritabanı: YENİ bir kurulum başlatılamaz (veri ezilmesi ve yabancı süper yönetici kapanır).
        // Yarıda kalmış kendi kurulumu (durum dosyası duruyor) devam edebilir; burada durum dosyası silinerek
        // "sıfırdan gelen ziyaretçi" canlandırılır.
        $this->post('https://ornek.test/install/yonetici', [
            'name' => 'Operatör',
            'email' => 'kurulum@ornek.test',
            'password' => 'Kurulum.Prova.2026!x',
            'password_confirmation' => 'Kurulum.Prova.2026!x',
        ])->assertRedirect(route('install.finish'));

        File::delete(storage_path(InstallGate::STATE));
        $this->flushSession();
        $this->get('/install')->assertNotFound();
    }

    #[Test]
    public function env_yazici_yalnizca_allowlist_anahtarlarini_ve_guvenli_degerleri_kabul_eder(): void
    {
        $writer = app(EnvWriter::class);

        $this->expectException(\DomainException::class);
        $writer->write(['APP_DEBUG' => 'true', 'EVIL_KEY' => 'x']);
    }

    #[Test]
    public function env_degerinde_satir_sonu_enjeksiyonu_reddedilir(): void
    {
        $writer = app(EnvWriter::class);

        $this->expectException(\DomainException::class);
        $writer->write(['APP_URL' => "https://ornek.test\nAPP_DEBUG=true"]);
    }
}
