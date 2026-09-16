<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\Permission;
use App\Models\UserRole;
use App\Models\Website;
use App\Security\MalwareScanner;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Açılış öncesi kontrol listesi (§76'nın işletim ayağı). Her madde
 * ok / uyarı / hata döner; ÜRETİMDE tek hata bile çıkış kodu 1'dir — deploy
 * betiği bunu kapı olarak kullanır (bkz. DEPLOY.md).
 *
 * Kurallar kod okunarak değil, çalıştırılarak doğrulanır: clamd'ye gerçek
 * bağlantı, önbelleğe gerçek yazma, migrasyon deposu, zamanlayıcının son
 * kalp atışı. Ortam config('app.env')'den okunur (testte değiştirilebilir).
 */
class DoctorCommand extends Command
{
    public const HEARTBEAT_KEY = 'ofisvio:scheduler:last_run';

    /** Zamanlayıcı bu süreden uzun sessizse uyarı (üretimde hata). */
    public const HEARTBEAT_MAX_MINUTES = 5;

    protected $signature = 'ofisvio:doctor {--json : Makine okunur çıktı}';

    protected $description = 'Ortam, güvenlik ve işletim ön koşullarını denetler; üretimde hata varsa 1 döner.';

    /** @var array<int, array{name: string, level: string, note: string}> */
    private array $rows = [];

    public function handle(Migrator $migrator, MalwareScanner $scanner): int
    {
        $this->rows = []; // komut nesnesi aynı süreçte yeniden çağrılabilir (test, tinker)
        $production = config('app.env') === 'production';

        $this->checkApp($production);
        $this->checkDatabase($migrator);
        $this->checkCache($production);
        $this->checkScanner($production, $scanner);
        $this->checkStorage();
        $this->checkMailAndQueue($production);
        $this->checkScheduler($production);
        $this->checkSeedAndAdmin();

        $failed = array_filter($this->rows, fn (array $r) => $r['level'] === 'fail');
        $warned = array_filter($this->rows, fn (array $r) => $r['level'] === 'warn');

        if ($this->option('json')) {
            $this->line((string) json_encode(['env' => config('app.env'), 'ok' => $failed === [], 'rows' => $this->rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->table(['', 'Kontrol', 'Not'], array_map(fn (array $r) => [
                match ($r['level']) {
                    'ok' => '✓',
                    'warn' => '!',
                    default => '✗',
                },
                $r['name'],
                $r['note'],
            ], $this->rows));

            $this->line(sprintf('%d kontrol · %d hata · %d uyarı · ortam: %s', count($this->rows), count($failed), count($warned), config('app.env')));
        }

        if ($failed !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function add(string $name, string $level, string $note = ''): void
    {
        $this->rows[] = ['name' => $name, 'level' => $level, 'note' => $note];
    }

    /** Üretimde hata, aksi halde uyarı. */
    private function strict(bool $production, string $name, string $note): void
    {
        $this->add($name, $production ? 'fail' : 'warn', $note);
    }

    private function checkApp(bool $production): void
    {
        $key = (string) config('app.key');
        $key === '' ? $this->add('APP_KEY', 'fail', 'Boş — php artisan key:generate') : $this->add('APP_KEY', 'ok');

        config('app.debug')
            ? $this->strict($production, 'APP_DEBUG', 'Açık — üretimde hata sayfası config/yol sızdırır')
            : $this->add('APP_DEBUG', 'ok', 'kapalı');

        $url = (string) config('app.url');
        Str::startsWith($url, 'https://')
            ? $this->add('APP_URL', 'ok', $url)
            : $this->strict($production, 'APP_URL', $url.' — https:// olmalı (canonical, sitemap, çerez güvenliği)');

        config('app.timezone') === 'Europe/Istanbul'
            ? $this->add('Saat dilimi', 'ok', 'Europe/Istanbul')
            : $this->add('Saat dilimi', 'warn', config('app.timezone').' — zamanlama ekranları İstanbul saatine göre yazılmıştır');
    }

    private function checkDatabase(Migrator $migrator): void
    {
        try {
            DB::connection()->getPdo();
            $driver = DB::connection()->getDriverName();
            $this->add('Veritabanı', 'ok', $driver.' bağlantısı');
        } catch (Throwable $e) {
            $this->add('Veritabanı', 'fail', 'Bağlanılamadı: '.$e->getMessage());

            return;
        }

        if (! $migrator->repositoryExists()) {
            $this->add('Migrasyonlar', 'fail', 'Migrasyon deposu yok — php artisan migrate --force');

            return;
        }

        $ran = $migrator->getRepository()->getRan();
        $files = $migrator->getMigrationFiles($migrator->paths() === [] ? [database_path('migrations')] : $migrator->paths());
        $pending = array_diff(array_keys($files), $ran);

        $pending === []
            ? $this->add('Migrasyonlar', 'ok', count($ran).' uygulanmış')
            : $this->add('Migrasyonlar', 'fail', count($pending).' bekliyor: '.implode(', ', array_slice($pending, 0, 3)));
    }

    private function checkCache(bool $production): void
    {
        $store = (string) config('cache.default');
        $probe = 'ofisvio:doctor:'.Str::random(8);

        try {
            Cache::put($probe, 'ok', 30);
            $roundTrip = Cache::pull($probe) === 'ok';
        } catch (Throwable $e) {
            $this->add('Önbellek', 'fail', $store.' — yazılamadı: '.$e->getMessage());

            return;
        }

        if (! $roundTrip) {
            $this->add('Önbellek', 'fail', $store.' — yazılan değer okunamadı');

            return;
        }

        in_array($store, ['array', 'null'], true)
            ? $this->strict($production, 'Önbellek', $store.' — istekler arası önbellek yok; ContentCache ve throttle çalışmaz')
            : $this->add('Önbellek', 'ok', $store);

        $session = (string) config('session.driver');
        $session === 'array'
            ? $this->strict($production, 'Oturum', 'array — oturum tutulmaz')
            : $this->add('Oturum', 'ok', $session);
    }

    private function checkScanner(bool $production, MalwareScanner $scanner): void
    {
        $driver = (string) config('ofisvio.kyc.scanner');

        if ($driver !== 'clamav') {
            // SecurityServiceProvider üretimde zaten açılışta reddeder; burada erken uyarı.
            $this->strict($production, 'KYC tarayıcı', $driver.' — üretimde clamav zorunlu (KYC_SCANNER=clamav, CLAMAV_ADDRESS)');

            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ofisvio-doctor');

        if ($tmp === false) {
            $this->add('KYC tarayıcı', 'fail', 'Geçici dosya yazılamadı');

            return;
        }

        try {
            file_put_contents($tmp, 'ofisvio doctor probe');
            $result = $scanner->scan($tmp);
        } finally {
            @unlink($tmp);
        }

        match (true) {
            ! $result->available => $this->add('KYC tarayıcı', 'fail', 'clamd erişilemez: '.($result->signature ?? '')),
            $result->isInfected() => $this->add('KYC tarayıcı', 'fail', 'Temiz sonda "virüslü" döndü — imza veritabanı bozuk olabilir'),
            default => $this->add('KYC tarayıcı', 'ok', 'clamav canlı tarama temiz ('.config('ofisvio.kyc.clamav.address').')'),
        };
    }

    private function checkStorage(): void
    {
        $disk = Storage::disk('private');
        $probe = 'doctor/'.Str::random(8).'.txt';

        try {
            $ok = $disk->put($probe, 'ok') && $disk->get($probe) === 'ok';
            $disk->delete($probe);
        } catch (Throwable $e) {
            $ok = false;
        }

        $ok
            ? $this->add('KYC depolama', 'ok', 'private disk yazılabilir')
            : $this->add('KYC depolama', 'fail', 'private disk yazılamıyor ('.config('filesystems.disks.private.root').')');
    }

    private function checkMailAndQueue(bool $production): void
    {
        $mailer = (string) config('mail.default');
        in_array($mailer, ['log', 'array'], true)
            ? $this->strict($production, 'E-posta', $mailer.' — davet, şifre sıfırlama ve 2FA kurtarma e-postaları gitmez')
            : $this->add('E-posta', 'ok', $mailer);

        $queue = (string) config('queue.default');
        $queue === 'sync'
            ? $this->add('Kuyruk', 'warn', 'sync — işler istek içinde koşar; yük altında worker kurun')
            : $this->add('Kuyruk', 'ok', $queue.' (worker çalışıyor mu? supervisor/systemd)');
    }

    private function checkScheduler(bool $production): void
    {
        $last = Cache::get(self::HEARTBEAT_KEY);

        if ($last === null) {
            $this->strict($production, 'Zamanlayıcı', 'Henüz hiç çalışmadı — cron: * * * * * php artisan schedule:run');
        } else {
            $minutes = (int) now()->diffInMinutes($last, true);
            $minutes > self::HEARTBEAT_MAX_MINUTES
                ? $this->strict($production, 'Zamanlayıcı', "Son çalışma {$minutes} dk önce — cron durmuş olabilir")
                : $this->add('Zamanlayıcı', 'ok', "son çalışma {$minutes} dk önce");
        }

        $overdue = Content::query()->where('status', 'SCHEDULED')->where('scheduled_for', '<', now())->count();
        $overdue > 0
            ? $this->add('Gecikmiş yayın', 'warn', "{$overdue} içerik zamanı geçtiği halde yayınlanmadı (bkz. /panel/icerik/takvim)")
            : $this->add('Gecikmiş yayın', 'ok', 'yok');
    }

    private function checkSeedAndAdmin(): void
    {
        $permissions = Permission::query()->count();
        $permissions > 0
            ? $this->add('RBAC matrisi', 'ok', $permissions.' izin')
            : $this->add('RBAC matrisi', 'fail', 'İzin yok — php artisan db:seed');

        Website::query()->where('is_default', true)->exists()
            ? $this->add('Varsayılan site', 'ok')
            : $this->add('Varsayılan site', 'fail', 'Yok — php artisan db:seed --class=WebsiteSeeder');

        $admins = UserRole::query()
            ->whereNull('company_id')->whereNull('organization_id')->whereNull('location_id')
            ->whereHas('role', fn ($q) => $q->where('type', 'internal'))
            ->count();
        $admins > 0
            ? $this->add('Personel hesabı', 'ok', $admins.' global rol ataması')
            : $this->add('Personel hesabı', 'warn', 'Yok — php artisan ofisvio:make-admin <e-posta> --name="..."');
    }
}
