<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Performans baseline artefaktı (faz 11): temsilî sayfaların sorgu sayısı,
 * süre ve tepe bellek ölçümü, JSON olarak. CI her koşuda üretir ve artefakt
 * olarak saklar; süre/bellek gürültülü olduğu için kapı DEĞİL, karşılaştırma
 * kaynağıdır. Sorgu sayısı kapısı QueryBudgetTest'tedir.
 *
 * Panel sayfaları için geçici bir personel hesabı transaction içinde açılır
 * ve komut sonunda geri alınır — veritabanında iz kalmaz. Yine de üretimde
 * çalıştırılmaz (APP_ENV=production reddedilir): ölçüm trafiği ve geçici
 * hesap üretim ortamına ait değildir.
 */
class PerfBaselineCommand extends Command
{
    protected $signature = 'ofisvio:perf-baseline
                            {--out= : JSON çıktı dosyası (varsayılan storage/app/perf/baseline.json)}
                            {--runs=3 : Sayfa başına ölçüm sayısı (ilk ısınma koşusu sayılmaz)}';

    protected $description = 'Temsilî sayfaların sorgu/süre/bellek ölçümünü JSON artefaktı olarak yazar.';

    /** yol => [etiket, personel gerekli mi] */
    private const PAGES = [
        '/' => ['Vitrin ana sayfa', false],
        '/blog' => ['Yazı listesi', false],
        '/lokasyonlar' => ['Lokasyonlar', false],
        '/sitemap.xml' => ['Sitemap', false],
        '/panel/icerik' => ['İçerik listesi', true],
        '/panel/icerik/takvim' => ['İçerik takvimi', true],
        '/panel/seo' => ['SEO paneli', true],
        '/panel/onbellek' => ['Önbellek paneli', true],
        '/panel/geo' => ['GEO paneli', true],
    ];

    public function handle(Kernel $kernel): int
    {
        if (config('app.env') === 'production') {
            $this->error('Baseline ölçümü üretimde çalıştırılmaz.');

            return self::FAILURE;
        }

        $runs = max(1, (int) $this->option('runs'));
        $out = (string) ($this->option('out') ?: storage_path('app/perf/baseline.json'));
        $results = [];

        DB::beginTransaction();

        try {
            $staff = $this->temporaryStaff();

            foreach (self::PAGES as $path => [$label, $needsStaff]) {
                $samples = [];

                for ($i = 0; $i <= $runs; $i++) {
                    $sample = $this->measure($kernel, $path, $needsStaff ? $staff : null);

                    if ($i > 0) { // ilk koşu ısınma (view cache, opcode)
                        $samples[] = $sample;
                    }
                }

                $results[] = [
                    'path' => $path,
                    'label' => $label,
                    'status' => $samples[0]['status'],
                    'queries' => max(array_column($samples, 'queries')),
                    'ms_median' => $this->median(array_column($samples, 'ms')),
                    'peak_mb' => round(max(array_column($samples, 'peak_bytes')) / 1048576, 1),
                ];
            }
        } finally {
            DB::rollBack();
            Auth::logout();
        }

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'cache_store' => config('cache.default'),
            'db' => DB::connection()->getDriverName(),
            'runs' => $runs,
            'pages' => $results,
        ];

        @mkdir(dirname($out), 0775, true);
        file_put_contents($out, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->table(['Sayfa', 'Durum', 'Sorgu', 'Süre (ms)', 'Tepe bellek (MB)'], array_map(fn (array $r) => [
            $r['label'].' '.$r['path'], $r['status'], $r['queries'], $r['ms_median'], $r['peak_mb'],
        ], $results));
        $this->info('Baseline yazıldı: '.$out);

        $bad = array_filter($results, fn (array $r) => $r['status'] !== 200);

        if ($bad !== []) {
            $this->error(count($bad).' sayfa 200 dönmedi.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array{status: int, queries: int, ms: float, peak_bytes: int} */
    private function measure(Kernel $kernel, string $path, ?User $staff): array
    {
        if ($staff !== null) {
            Auth::login($staff);
        } else {
            Auth::logout();
        }

        $request = Request::create($path, 'GET', server: ['HTTP_HOST' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        $response = $kernel->handle($request);
        $ms = (hrtime(true) - $start) / 1e6;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $kernel->terminate($request, $response);

        return ['status' => $response->getStatusCode(), 'queries' => $queries, 'ms' => round($ms, 1), 'peak_bytes' => memory_get_peak_usage(true)];
    }

    /** Transaction içinde açılan, komut sonunda geri alınan 2FA'lı personel. */
    private function temporaryStaff(): User
    {
        $user = User::query()->create([
            'name' => 'Baseline Ölçüm',
            'email' => 'baseline-'.Str::lower(Str::random(8)).'@ofisvio.invalid',
            'password' => Str::random(40),
        ]);

        $user->forceFill([
            'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode([])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $roleId = Role::query()->where('name', 'system_admin')->value('id');

        if ($roleId === null) {
            throw new \RuntimeException('system_admin rolü yok — önce db:seed.');
        }

        UserRole::query()->create(['user_id' => $user->id, 'role_id' => $roleId]);

        return $user;
    }

    /** @param  array<int, float>  $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? $values[intdiv($n, 2)] : round(($values[$n / 2 - 1] + $values[$n / 2]) / 2, 1);
    }
}
