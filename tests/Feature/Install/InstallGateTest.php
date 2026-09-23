<?php

namespace Tests\Feature\Install;

use App\Install\InstallGate;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Panel\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Kurulum ucunun kapısı (faz 62): uç, operatör sunucuda anahtar dosyasını oluşturana kadar YOKTUR ve kurulum
 * bitince kalıcı olarak kapanır. Kapalı olmanın her nedeni aynı 404'ü döndürür — "burada kurulmamış bir Ofisvio
 * var" bilgisi dışarı sızmaz.
 *
 * Testler storage yolunu geçici dizine alır: gerçek geliştirme kurulumunun kilidi/anahtarı etkilenmez.
 */
class InstallGateTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = storage_path('framework/testing/install-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->storage.'/app/install');
        File::ensureDirectoryExists($this->storage.'/framework/sessions');
        File::ensureDirectoryExists($this->storage.'/framework/views');
        File::ensureDirectoryExists($this->storage.'/logs');
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    #[Test]
    public function anahtar_dosyasi_yokken_kurulum_ucu_yoktur(): void
    {
        $this->get('/install')->assertNotFound();
        $this->post('/install', ['token' => str_repeat('a', 40)])->assertNotFound();
        $this->get('/install/gereksinimler')->assertNotFound();
    }

    #[Test]
    public function kisa_ya_da_suresi_gecmis_anahtar_dosyasi_ucu_acmaz(): void
    {
        $this->writeChallenge('kisa-anahtar');
        $this->get('/install')->assertNotFound();

        $path = $this->writeChallenge(str_repeat('b', 40));
        touch($path, time() - InstallGate::TTL_SECONDS - 60);
        clearstatcache();
        $this->get('/install')->assertNotFound();
    }

    #[Test]
    public function gecerli_anahtar_dosyasi_ekrani_acar_yanlis_anahtar_gecmez(): void
    {
        $this->writeChallenge(str_repeat('c', 40));

        $this->get('/install')->assertOk()->assertSee('Kurulum anahtarı');

        $this->post('/install', ['token' => str_repeat('x', 40)])
            ->assertRedirect()
            ->assertSessionHasErrors('token');
        $this->assertNull(session(InstallGate::SESSION_KEY));

        $this->post('/install', ['token' => str_repeat('c', 40)])->assertRedirect(route('install.requirements'));
        $this->assertTrue(session(InstallGate::SESSION_KEY));
        $this->get('/install/gereksinimler')->assertOk()->assertSee('PHP sürümü');
    }

    #[Test]
    public function anahtar_dogrulanmadan_hicbir_adim_acilmaz(): void
    {
        $this->writeChallenge(str_repeat('d', 40));

        foreach (['/install/gereksinimler', '/install/veritabani', '/install/site', '/install/kurulum', '/install/yonetici', '/install/bitir'] as $path) {
            $this->get($path)->assertNotFound();
        }

        $this->post('/install/kurulum/tablolar')->assertNotFound();
        $this->post('/install/yonetici', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'Cok-Guclu-Sifre-2026!'])->assertNotFound();
    }

    #[Test]
    public function kilit_dosyasi_varken_uc_kapalidir(): void
    {
        $this->writeChallenge(str_repeat('e', 40));
        File::put(storage_path(InstallGate::LOCK), 'kurulu');

        $this->get('/install')->assertNotFound();
        $this->assertTrue(app(InstallGate::class)->installed());
    }

    #[Test]
    public function dolu_veritabani_ustune_kurulum_baslatilamaz(): void
    {
        $this->writeChallenge(str_repeat('f', 40));
        $this->seedRbac();
        $this->staff('system_admin'); // users tablosunda kayıt oluşur

        $this->get('/install')->assertNotFound();
    }

    #[Test]
    public function farkli_ip_civilenmis_kurulumu_devralamaz(): void
    {
        $this->writeChallenge(str_repeat('g', 40));
        $gate = app(InstallGate::class);

        $this->assertTrue($gate->verify(str_repeat('g', 40), '203.0.113.10'));
        $this->assertTrue($gate->ipAllowed('203.0.113.10'));
        $this->assertFalse($gate->ipAllowed('198.51.100.7'));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/install')->assertNotFound();
    }

    #[Test]
    public function kapanis_kilidi_yazar_ve_anahtari_siler(): void
    {
        $this->writeChallenge(str_repeat('h', 40));
        $gate = app(InstallGate::class);
        $gate->verify(str_repeat('h', 40), '203.0.113.10');

        $gate->complete();

        $this->assertFileExists(storage_path(InstallGate::LOCK));
        $this->assertFileDoesNotExist($gate->challengePath());
        $this->assertFileDoesNotExist(storage_path(InstallGate::IP_PIN));
        $this->assertFalse($gate->open());
        $this->get('/install')->assertNotFound();
    }

    #[Test]
    public function doctor_acik_kalmis_kurulum_ucunu_uretimde_hata_sayar(): void
    {
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        config(['app.env' => 'production']);
        $this->writeChallenge(str_repeat('i', 40));

        Artisan::call('ofisvio:doctor', ['--json' => true]);
        $rows = collect((array) (json_decode(Artisan::output(), true)['rows'] ?? []))->keyBy('name');

        $this->assertSame('fail', $rows['Kurulum ucu']['level']);

        File::delete(storage_path(InstallGate::CHALLENGE));
        Artisan::call('ofisvio:doctor', ['--json' => true]);
        $rows = collect((array) (json_decode(Artisan::output(), true)['rows'] ?? []))->keyBy('name');

        $this->assertSame('ok', $rows['Kurulum ucu']['level']);
    }

    private function writeChallenge(string $token): string
    {
        $path = storage_path(InstallGate::CHALLENGE);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $token."\n");

        return $path;
    }
}
