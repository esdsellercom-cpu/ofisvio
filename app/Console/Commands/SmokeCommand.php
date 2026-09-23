<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deploy sonrası doğrulama (audit F-02 / §34): uygulama içi gerçek istekler ve alt sistem yoklamaları. Doctor ortamı
 * denetler; smoke ÇALIŞAN sistemi: HTTP rotaları (vitrin 200, giriş 200, panel yetkisiz → yönlendirme, imzasız webhook →
 * 4xx), DB + migration, önbellek yaz/oku, kuyruk bağlantısı, zamanlayıcı kalp atışı, depolama yaz/sil, şifreleme
 * gidiş-dönüş, tenant izolasyonu (bağlam yokken şirket sorgusu 0 satır), kritik iş akışı (rezervasyon uygunluk sayfası).
 * Bir kontrol bile düşerse çıkış 1 → deploy betiği bakım modunu AÇIK bırakır.
 */
class SmokeCommand extends Command
{
    protected $signature = 'ofisvio:smoke {--json : Makine okunur çıktı}';

    protected $description = 'Deploy sonrası çalışan sistemi uçtan uca yoklar; hata varsa 1 döner (bakım modu açık kalır).';

    /** @var array<int, array{name: string, ok: bool, note: string}> */
    private array $rows = [];

    public function handle(Kernel $kernel): int
    {
        $this->rows = [];

        $this->http($kernel, 'Vitrin ana sayfa', 'GET', '/', [200]);
        $this->http($kernel, 'Giriş sayfası', 'GET', '/login', [200]);
        $this->http($kernel, 'Panel yetkisiz → yönlendirme', 'GET', '/panel', [302]);
        $this->http($kernel, 'İmzasız webhook reddi', 'POST', '/webhooks/iyzico', [400, 401, 404, 413]);
        $this->http($kernel, 'robots.txt', 'GET', '/robots.txt', [200]);
        $this->http($kernel, 'Kurulum ucu kapalı', 'GET', '/install', [404]); // faz 62: kurulu sistemde sihirbaz yoktur
        $this->http($kernel, 'Kritik akış: rezervasyon uygunluk', 'GET', '/rezervasyon', [200]);

        $this->probe('Veritabanı', function () {
            DB::select('select 1');

            return DB::connection()->getDriverName();
        });
        $this->probe('Migration', function () {
            $pending = DB::table('migrations')->count();

            return $pending.' kayıt';
        });
        $this->probe('Önbellek', function () {
            $k = 'ofisvio:smoke:'.Str::random(8);
            Cache::put($k, 'ok', 30);

            if (Cache::pull($k) !== 'ok') {
                throw new \RuntimeException('yazılan değer okunamadı');
            }

            return (string) config('cache.default');
        });
        $this->probe('Kuyruk', function () {
            $size = Queue::size();

            return config('queue.default').' · bekleyen '.$size;
        });
        $this->probe('Zamanlayıcı', function () {
            $last = Cache::get(DoctorCommand::HEARTBEAT_KEY);

            if ($last === null) {
                throw new \RuntimeException('kalp atışı yok (cron çalışmıyor?)');
            }

            return 'son çalışma '.$last;
        });
        $this->probe('Depolama', function () {
            $k = 'smoke/'.Str::random(8).'.txt';
            Storage::disk('private')->put($k, 'ok');
            $read = Storage::disk('private')->get($k);
            Storage::disk('private')->delete($k);

            if ($read !== 'ok') {
                throw new \RuntimeException('özel diske yazılamadı');
            }

            return 'private yaz/oku/sil';
        });
        $this->probe('Şifreleme', function () {
            $plain = Str::random(16);

            if (Crypt::decryptString(Crypt::encryptString($plain)) !== $plain) {
                throw new \RuntimeException('gidiş-dönüş başarısız');
            }

            return 'APP_KEY gidiş-dönüş';
        });
        $this->probe('Tenant izolasyonu', function () {
            app(TenantContext::class)->clear();
            $n = Company::query()->count(); // bağlam yok → TenantScope 1=0 → 0 satır

            if ($n !== 0) {
                throw new \RuntimeException('bağlam yokken '.$n.' şirket döndü');
            }

            return 'bağlamsız sorgu 0 satır';
        });

        $failed = array_filter($this->rows, fn (array $r) => ! $r['ok']);

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => $failed === [], 'rows' => $this->rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->table(['', 'Kontrol', 'Not'], array_map(fn (array $r) => [$r['ok'] ? '✓' : '✗', $r['name'], $r['note']], $this->rows));
            $this->line(count($this->rows).' kontrol · '.count($failed).' hata');
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<int, int>  $expected */
    private function http(Kernel $kernel, string $name, string $method, string $path, array $expected): void
    {
        try {
            $request = Request::create($path, $method, [], [], [], ['HTTP_HOST' => (string) parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost', 'HTTPS' => str_starts_with((string) config('app.url'), 'https') ? 'on' : 'off']);
            $response = $kernel->handle($request);
            $status = $response->getStatusCode();
            $kernel->terminate($request, $response);
            $this->rows[] = ['name' => $name, 'ok' => in_array($status, $expected, true), 'note' => $method.' '.$path.' → '.$status.(in_array($status, $expected, true) ? '' : ' (beklenen '.implode('/', $expected).')')];
        } catch (Throwable $e) {
            $this->rows[] = ['name' => $name, 'ok' => false, 'note' => $method.' '.$path.' → '.mb_substr($e->getMessage(), 0, 120)];
        }
    }

    private function probe(string $name, callable $fn): void
    {
        try {
            $this->rows[] = ['name' => $name, 'ok' => true, 'note' => (string) $fn()];
        } catch (Throwable $e) {
            $this->rows[] = ['name' => $name, 'ok' => false, 'note' => mb_substr($e->getMessage(), 0, 160)];
        }
    }
}
