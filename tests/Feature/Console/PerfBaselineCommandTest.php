<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Database\Seeders\LocationSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Panel\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Faz 11 — ofisvio:perf-baseline: JSON artefaktı üretir, tüm sayfalar 200,
 * geçici personel geri alınır, üretimde reddedilir.
 */
class PerfBaselineCommandTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    #[Test]
    public function artefakt_uretir_ve_iz_birakmaz(): void
    {
        $this->seedRbac();
        $this->seed(LocationSeeder::class);
        $this->seed(WebsiteSeeder::class);

        $out = sys_get_temp_dir().'/ofisvio-baseline-'.uniqid().'.json';
        $before = User::count();

        $this->assertSame(0, Artisan::call('ofisvio:perf-baseline', ['--out' => $out, '--runs' => 1]), Artisan::output());

        $json = json_decode((string) file_get_contents($out), true);
        @unlink($out);

        $this->assertCount(9, $json['pages']);
        $this->assertSame([200], array_values(array_unique(array_column($json['pages'], 'status'))));
        $this->assertGreaterThan(0, $json['pages'][0]['queries']);
        $this->assertArrayHasKey('ms_median', $json['pages'][0]);
        $this->assertSame($before, User::count(), 'Geçici personel geri alınmalı.');

        config(['app.env' => 'production']);
        $this->assertSame(1, Artisan::call('ofisvio:perf-baseline', ['--out' => $out, '--runs' => 1]));
    }
}
