<?php

namespace Tests\Feature\Install;

use App\Install\EnvWriter;
use App\Install\InstallGate;
use App\Install\InstallWizardService;
use App\Models\User;
use App\Security\MalwareScanner;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kurulum sihirbazının sertleştirmeleri (faz 62 güvenlik incelemesinin bulguları — her biri burada kalıcı testte).
 *
 * Kapsanan bulgular: anahtar denemesinin hız sınırı, IP çivisinin proxy başlığıyla atlanamaması, terk edilmiş
 * kurulumun penceresinin kapanması, .env değerinde `$1` kaçışının bozulmaması, .env yedeğinin web kökü dışına
 * yazılması, ağır adımın tek uçuşu, SQLite dosyasının web köküne açılamaması ve var olan e-postaya sessizce
 * süper yönetici verilmemesi.
 */
class InstallHardeningTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();

        $base = storage_path('framework/testing/hard-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($base.'/app/install');
        File::ensureDirectoryExists($base.'/framework/sessions');
        File::ensureDirectoryExists($base.'/framework/views');
        File::ensureDirectoryExists($base.'/logs');
        $this->storage = $base;
        $this->envFile = $base.'/.env.kurulum';
        File::copy(base_path('.env.example'), $this->envFile);
        config(['ofisvio.install.env_file' => $this->envFile]);
        $this->app->useStoragePath($base);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    #[Test]
    public function anahtar_denemesi_dakikada_bes_ile_sinirli(): void
    {
        $this->writeChallenge(str_repeat('A', 40));

        for ($i = 0; $i < 5; $i++) {
            $this->post('/install', ['token' => str_repeat('z', 40)])->assertRedirect();
        }

        $this->post('/install', ['token' => str_repeat('z', 40)])->assertStatus(429);
    }

    #[Test]
    public function ip_civisi_proxy_basligiyla_atlanamaz(): void
    {
        $this->writeChallenge(str_repeat('B', 40));
        config(['ofisvio.security.trusted_proxies' => '*']); // kurulumun site adımı bunu yazabiliyor

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/install', ['token' => str_repeat('B', 40)])
            ->assertRedirect(route('install.requirements'));

        // Saldırgan başka bir makineden, kurbanın adresini X-Forwarded-For ile taklit ediyor.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.10'])
            ->get('/install')
            ->assertNotFound();
    }

    #[Test]
    public function terk_edilmis_kurulumun_penceresi_kapanir(): void
    {
        $this->writeChallenge(str_repeat('C', 40));
        app(InstallGate::class)->verify(str_repeat('C', 40), '203.0.113.10');

        $this->assertTrue(app(InstallGate::class)->open());

        touch(storage_path(InstallGate::STATE), time() - InstallGate::STARTED_TTL_SECONDS - 60);
        touch(storage_path(InstallGate::CHALLENGE), time() - InstallGate::TTL_SECONDS - 60);
        clearstatcache();

        $this->assertFalse(app(InstallGate::class)->open());
        $this->get('/install')->assertNotFound();
    }

    #[Test]
    public function env_degerindeki_kacis_dizileri_birebir_yazilir(): void
    {
        $writer = app(EnvWriter::class);
        $password = 'a$1b\1c$0d';

        $writer->write(['DB_PASSWORD' => $password]);

        // Dosyada kaçışlı durur ama okunduğunda BİREBİR aynı değere döner (preg_replace ikamesi $1'i yutuyordu).
        $parsed = Dotenv::parse((string) file_get_contents($this->envFile));
        $this->assertSame($password, $parsed['DB_PASSWORD']);
    }

    #[Test]
    public function env_yedegi_web_kokunun_disina_yazilir_ve_kapanista_silinir(): void
    {
        app(EnvWriter::class)->write(['APP_URL' => 'https://ornek.test']);

        $backup = storage_path(InstallGate::DIRECTORY.'/env-backup.txt');
        $this->assertFileExists($backup);
        $this->assertFileDoesNotExist($this->envFile.'.backup');

        app(InstallGate::class)->complete();
        $this->assertFileDoesNotExist($backup);
    }

    #[Test]
    public function ayni_agir_adim_iki_kez_baslatilamaz(): void
    {
        $this->writeChallenge(str_repeat('D', 40));
        $wizard = app(InstallWizardService::class);

        File::put(storage_path(InstallGate::RUNNING), "migrate\n"); // süren bir adım

        $this->expectException(DomainException::class);
        $wizard->migrate();
    }

    #[Test]
    public function sqlite_dosyasi_web_kokune_acilamaz(): void
    {
        $wizard = app(InstallWizardService::class);

        $this->expectException(DomainException::class);
        $wizard->saveDatabase(['connection' => 'sqlite', 'database' => public_path('veritabani.sqlite')]);
    }

    #[Test]
    public function var_olan_e_postaya_sessizce_super_admin_verilmez(): void
    {
        $this->writeChallenge(str_repeat('E', 40));
        $this->seed(RolePermissionSeeder::class);
        User::create(['name' => 'Var Olan', 'email' => 'var@ornek.test', 'password' => 'Baska.Bir.Sifre.2026!']);

        $this->expectException(DomainException::class);
        app(InstallWizardService::class)->createAdmin(['name' => 'Operatör', 'email' => 'var@ornek.test', 'password' => 'Kurulum.Prova.2026!x']);
    }

    #[Test]
    public function clamd_olmayan_uretimde_sistem_acilir_ama_belge_yuklenemez(): void
    {
        // Üretimde KYC_SCANNER=none kapta istisna atıp TÜM siteyi 500'e düşürüyordu (paylaşımlı hostingde clamd yok).
        config(['app.env' => 'production', 'ofisvio.kyc.scanner' => 'disabled']);
        $this->app->forgetInstance(MalwareScanner::class);

        $result = app(MalwareScanner::class)->scan(__FILE__);

        $this->assertFalse($result->available); // tarama yapılamadı → yükleme reddedilir (fail-closed)
        $this->assertFalse($result->clean);

        Artisan::call('ofisvio:doctor', ['--json' => true]);
        $rows = collect((array) (json_decode(Artisan::output(), true)['rows'] ?? []))->keyBy('name');
        $this->assertSame('warn', $rows['KYC tarayıcı']['level']);
    }

    private function writeChallenge(string $token): void
    {
        File::ensureDirectoryExists(storage_path(InstallGate::DIRECTORY));
        File::put(storage_path(InstallGate::CHALLENGE), $token."\n");
    }
}
