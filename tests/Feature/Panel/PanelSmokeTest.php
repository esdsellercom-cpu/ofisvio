<?php

namespace Tests\Feature\Panel;

use Database\Seeders\LocationSeeder;
use Database\Seeders\NotificationRuleSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Üretim denetimi (faz 52): parametresiz her panel GET rotası, her ana rol için 500 vermeden açılmalı
 * (200, 302 yönlendirme ya da 403 yetki). Boş sayfa / kırık görünüm / eksik değişken burada yakalanır.
 * Kimliksiz istek her panel rotasında girişe yönlenir.
 */
class PanelSmokeTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    /** @return array<int, string> */
    private function panelGetUris(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (in_array('GET', $route->methods(), true) && str_starts_with($uri, 'panel') && ! str_contains($uri, '{')) {
                $uris[] = '/'.$uri;
            }
        }

        sort($uris);

        return array_values(array_unique($uris));
    }

    #[Test]
    public function parametresiz_her_panel_sayfasi_ana_roller_icin_500_vermeden_acilir(): void
    {
        $this->seedRbac();
        $this->seed(LocationSeeder::class);
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
        $this->seed(NotificationRuleSeeder::class);
        $org = $this->organization('Acme');
        $co = $this->company($org, 'Acme A.Ş.');
        $owner = $this->owner($org, $co);
        $uris = $this->panelGetUris();
        $this->assertGreaterThan(40, count($uris));

        // Kimliksiz (actingAs'tan ÖNCE — oturum test boyunca kalır): her rota girişe yönlenir.
        foreach ($uris as $uri) {
            $status = $this->get($uri)->getStatusCode();
            $this->assertContains($status, [302, 401, 403], "$uri kimliksiz → $status");
        }

        $actors = ['system_admin' => $this->staff('system_admin'), 'super_admin' => $this->staff('super_admin'), 'finance_admin' => $this->staff('finance_admin'), 'operations_admin' => $this->staff('operations_admin'), 'owner' => $owner];
        $failures = [];

        foreach ($actors as $role => $user) {
            foreach ($uris as $uri) {
                $status = $this->actingAs($user)->withContext($org)->get($uri)->getStatusCode();

                if (! in_array($status, [200, 302, 403], true)) {
                    $failures[] = "$role $uri → $status";
                }
            }
        }

        $this->assertSame([], $failures, "500/404 veren panel sayfaları:\n".implode("\n", $failures));
    }
}
