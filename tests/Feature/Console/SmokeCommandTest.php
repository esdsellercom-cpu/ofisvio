<?php

namespace Tests\Feature\Console;

use App\Console\Commands\DoctorCommand;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit F-02 (§34 deploy sonrası doğrulama): ofisvio:smoke çalışan sistemi gerçek isteklerle yoklar; zamanlayıcı kalp
 * atışı yoksa 1 döner (deploy betiği bakımda kalır), kalp atışı gelince tüm kontroller geçer.
 */
class SmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function smoke_kalp_atisi_yokken_duser_sonra_tum_kontroller_gecer(): void
    {
        $this->seed(WebsiteSeeder::class);

        $this->artisan('ofisvio:smoke')->expectsOutputToContain('kalp atışı yok')->assertFailed();

        Cache::put(DoctorCommand::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay());
        $this->artisan('ofisvio:smoke', ['--json' => true])->assertSuccessful();
        $this->artisan('ofisvio:smoke')
            ->expectsOutputToContain('GET / → 200')
            ->expectsOutputToContain('GET /panel → 302')
            ->expectsOutputToContain('POST /webhooks/iyzico → 404')
            ->expectsOutputToContain('bağlamsız sorgu 0 satır')
            ->expectsOutputToContain('GET /install → 404') // faz 62: kurulu sistemde kurulum sihirbazı yoktur
            ->expectsOutputToContain('15 kontrol · 0 hata')
            ->assertSuccessful();

        // Kurulum ön kontrolü de koşar (--check yazmaz).
        $this->artisan('ofisvio:install', ['--check' => true])->expectsOutputToContain('Ön kontrol tamam')->assertSuccessful();
    }
}
