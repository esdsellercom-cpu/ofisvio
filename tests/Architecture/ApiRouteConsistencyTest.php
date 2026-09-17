<?php

namespace Tests\Architecture;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Audit — frontend ↔ backend sözleşmesi (sunucu taraflı Blade):
 *   - Görünümlerde route('...') ile çağrılan her ad tanımlı (submit var ama endpoint yok = ölü UI).
 *   - Her form action bir route() üretir (sabit yazılmış /panel/... yok).
 *   - Her adlandırılmış panel rotasında permission ya da auth zinciri var (bkz. ArchitectureTest).
 */
class ApiRouteConsistencyTest extends TestCase
{
    /** @return array<int, string> */
    private static function views(): array
    {
        $root = dirname(__DIR__, 2).'/resources/views';
        $out = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    #[Test]
    public function gorunumlerde_cagrilan_her_route_adi_tanimli(): void
    {
        $missing = [];

        foreach (self::views() as $path) {
            $source = (string) file_get_contents($path);
            // $request->route('token') parametre okumasıdır; 'x.'.$name dinamik ad — ikisi kapsam dışı.
            preg_match_all("/(?<![>\\w])route\\(\\s*'([a-z0-9_.\\-]+)'\\s*[,)]/i", $source, $m);

            foreach (array_unique($m[1]) as $name) {
                if (str_ends_with($name, '.')) {
                    continue;
                }

                if (! Route::has($name)) {
                    $missing[] = basename($path).' → '.$name;
                }
            }
        }

        $this->assertSame([], $missing, "Görünümde çağrılan ama tanımsız rota adları:\n".implode("\n", $missing));
    }

    #[Test]
    public function form_actionlari_sabit_yol_degil_route_uretir(): void
    {
        $offenders = [];

        foreach (self::views() as $path) {
            $source = (string) file_get_contents($path);
            preg_match_all('/<form[^>]*action="([^"]*)"/i', $source, $m);

            foreach ($m[1] as $action) {
                // İzinli: route(...), $değişken, url(...), '#' ya da boş (aynı sayfa GET süzgeçleri).
                if ($action === '' || str_starts_with($action, '{{') || str_starts_with($action, '{!!') || str_starts_with($action, '#')) {
                    continue;
                }

                $offenders[] = basename($path).': action="'.$action.'"';
            }
        }

        $this->assertSame([], $offenders, "Sabit yazılmış form action'ları:\n".implode("\n", $offenders));
    }

    #[Test]
    public function panel_rotalari_kimlik_ve_yetki_zinciri_tasiyor(): void
    {
        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'panel')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $hasAuth = in_array('auth', $middleware, true) || in_array('auth:web', $middleware, true);

            if (! $hasAuth) {
                $unguarded[] = implode('|', $route->methods()).' '.$uri.' (auth yok)';
            }

            // Hesap/context ekranları dışındaki her panel rotası permission ya da tenant taşır.
            // Muaf: hesap, organizasyon seçimi, kök; gelen kutusu (kullanıcının KENDİ uygulama içi bildirimleri — sorgu notifiable ile sınırlı);
            // üst çubuk araması (panel/ara): kümeler PanelSearchService içinde izne göre süzülür, izinsiz küme sorgulanmaz.
            $exempt = str_starts_with($uri, 'panel/hesap') || str_starts_with($uri, 'panel/organizasyon') || str_starts_with($uri, 'panel/bildirimler/gelen') || $uri === 'panel' || $uri === 'panel/ara';
            $hasPermission = collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));
            $hasTenant = in_array('tenant', $middleware, true);

            if (! $exempt && ! $hasPermission && ! $hasTenant) {
                $unguarded[] = implode('|', $route->methods()).' '.$uri.' (permission/tenant yok)';
            }
        }

        $this->assertSame([], $unguarded, "Korumasız panel rotaları:\n".implode("\n", $unguarded));
    }
}
