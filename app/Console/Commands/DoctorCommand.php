<?php

namespace App\Console\Commands;

use App\Install\InstallGate;
use App\Integrations\SecretStore;
use App\Models\Content;
use App\Models\Media;
use App\Models\Permission;
use App\Models\UserRole;
use App\Models\Website;
use App\Security\MalwareScanner;
use App\Services\BackupService;
use App\Services\KeyRotationService;
use App\Services\LegalDocumentService;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
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

    /** Audit F-04: DB sunucusu ile uygulama saati arasında izin verilen fark (saniye). */
    public const CLOCK_DRIFT_MAX_SECONDS = 5;

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
        $this->checkMedia();
        $this->checkMailAndQueue($production);
        $this->checkScheduler($production);
        $this->checkSeedAndAdmin();
        $this->checkInstallEndpoint($production);
        $this->checkLegal($production);
        $this->checkKeyRotation($production);
        $this->checkFailedJobs();
        $this->checkClock($production);
        $this->checkBackup($production);
        $this->checkIntegrations($production);

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

        // Ters proxy (audit F-12): üretimde neredeyse her zaman nginx/CDN vardır; güven tanımsızsa HTTPS algılanmaz.
        $proxies = trim((string) config('ofisvio.security.trusted_proxies', ''));
        $proxies !== ''
            ? $this->add('Ters proxy', 'ok', 'TRUSTED_PROXIES='.$proxies)
            : $this->add('Ters proxy', $production ? 'warn' : 'ok', 'TRUSTED_PROXIES boş — proxy/CDN arkasındaysanız X-Forwarded-Proto yok sayılır (HSTS, secure çerez, https URL)');

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

        // Çerez sertleştirme (audit S-2): üretimde secure + httponly + same_site zorunlu.
        $secure = (bool) config('session.secure');
        $sameSite = (string) config('session.same_site');
        $secure && (bool) config('session.http_only') && in_array($sameSite, ['lax', 'strict'], true)
            ? $this->add('Oturum çerezi', 'ok', 'secure · httponly · same_site='.$sameSite)
            : $this->strict($production, 'Oturum çerezi', 'SESSION_SECURE_COOKIE=true, SESSION_HTTP_ONLY=true, SESSION_SAME_SITE=lax|strict olmalı');
    }

    private function checkScanner(bool $production, MalwareScanner $scanner): void
    {
        if ((string) config('ofisvio.kyc.scanner') === 'disabled') {
            $this->add('KYC tarayıcı', 'warn', 'disabled — belge yükleme kapalı (taranmamış belge kabul edilmez). clamd kurulunca KYC_SCANNER=clamav yapın.');

            return;
        }

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

    /**
     * Vitrin görselleri: public disk bağlantısı (`public/storage`) ve kayıtlı medya dosyalarının diskte gerçekten
     * var olması. Eksik dosya = vitrinde kırık görsel; sayı ve ilk örnekler raporlanır.
     */
    private function checkMedia(): void
    {
        $link = public_path('storage');

        if (! is_dir($link)) {
            $this->add('Medya deposu', 'fail', 'public/storage bağlantısı yok — `php artisan storage:link` çalıştırın; görseller yüklenmez');

            return;
        }

        $missing = [];
        $total = 0;

        Media::query()->select(['id', 'disk', 'path', 'variants'])->orderBy('id')->chunk(200, function ($rows) use (&$missing, &$total) {
            foreach ($rows as $media) {
                $total++;

                foreach ($media->allPaths() as $path) {
                    if (! Storage::disk($media->disk)->exists($path)) {
                        $missing[] = '#'.$media->id.' '.basename($path);
                    }
                }
            }
        });

        $missing === []
            ? $this->add('Medya dosyaları', 'ok', $total.' kayıt, tüm dosyalar diskte')
            : $this->add('Medya dosyaları', 'warn', count($missing).' eksik dosya: '.implode(', ', array_slice($missing, 0, 5)).(count($missing) > 5 ? ' …' : ''));
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

    /** Açık sağlayıcı: secret'ları tam ve base_url https olmalı; kapalı sağlayıcı bilgi satırı. */
    private function checkIntegrations(bool $production): void
    {
        $secrets = app(SecretStore::class);
        $enabled = 0;

        foreach ((array) config('integrations.providers') as $key => $provider) {
            if (! ($provider['enabled'] ?? false)) {
                continue;
            }

            $enabled++;
            $missing = $secrets->missing((string) $key);
            $https = str_starts_with((string) ($provider['base_url'] ?? ''), 'https://');

            match (true) {
                $missing !== [] => $this->add('Entegrasyon: '.$key, 'fail', 'Açık ama eksik secret: '.implode(', ', $missing)),
                ! $https => $this->add('Entegrasyon: '.$key, 'fail', 'base_url https:// olmalı'),
                default => $this->add('Entegrasyon: '.$key, 'ok', 'açık, secret tanımlı'),
            };
        }

        if ($enabled === 0) {
            $this->add('Entegrasyonlar', 'ok', 'açık sağlayıcı yok (hepsi env ile kapalı)');
        }
    }

    /**
     * Audit F-04: saat doğruluğu — webhook tekrar penceresi, JIT süresi, TOTP, imzalı URL saat kaymasına duyarlıdır.
     * (1) DB sunucusu ile uygulama saati farkı (çok sunuculu kurulumda gerçek sapma); (2) Linux'ta NTP senkron durumu
     * (timedatectl / chronyc). Denetlenemeyen durum üretimde uyarı, sapma > CLOCK_DRIFT_MAX_SECONDS üretimde hata.
     */
    private function checkClock(bool $production): void
    {
        try {
            $dbEpoch = match (DB::connection()->getDriverName()) {
                'sqlite' => (int) DB::selectOne("select strftime('%s', 'now') as t")->t,
                'mysql', 'mariadb' => (int) DB::selectOne('select unix_timestamp() as t')->t,
                'pgsql' => (int) DB::selectOne('select extract(epoch from now()) as t')->t,
                default => null,
            };
        } catch (Throwable $e) {
            $dbEpoch = null;
        }

        if ($dbEpoch !== null) {
            $drift = abs(time() - $dbEpoch);
            $drift <= self::CLOCK_DRIFT_MAX_SECONDS
                ? $this->add('Saat (DB ↔ uygulama)', 'ok', $drift.' sn fark')
                : $this->strict($production, 'Saat (DB ↔ uygulama)', $drift.' sn sapma — NTP/chrony ayarlayın (webhook, JIT, 2FA, imzalı URL etkilenir)');
        }

        if (! (bool) config('ofisvio.ops.ntp_check', true)) {
            $this->add('NTP senkronu', $production ? 'warn' : 'ok', 'Denetim kapalı (OFISVIO_NTP_CHECK=false) — yalnız test/CI konteyneri için; üretimde açık tutun');

            return;
        }

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->add('NTP senkronu', $production ? 'warn' : 'ok', 'Yalnız Linux üzerinde denetlenir (timedatectl/chronyc)');

            return;
        }

        $status = $this->ntpStatus();
        match ($status) {
            'yes' => $this->add('NTP senkronu', 'ok', 'sistem saati senkron'),
            'no' => $this->strict($production, 'NTP senkronu', 'Sistem saati senkron DEĞİL — chrony/systemd-timesyncd etkinleştirin'),
            default => $this->add('NTP senkronu', 'warn', 'Denetlenemedi (timedatectl/chronyc yok) — sunucuda NTP çalıştığını elle doğrulayın'),
        };
    }

    /** 'yes' | 'no' | 'unknown' */
    private function ntpStatus(): string
    {
        foreach ([['timedatectl', 'show', '-p', 'NTPSynchronized', '--value'], ['chronyc', '-c', 'tracking']] as $cmd) {
            try {
                $process = new Process($cmd, null, null, null, 5);
                $process->run();

                if (! $process->isSuccessful()) {
                    continue;
                }

                $out = trim($process->getOutput());

                if ($cmd[0] === 'timedatectl') {
                    return $out === 'yes' ? 'yes' : 'no';
                }

                // chronyc -c tracking: 4. alan (leap status) 0 = normal, 3 = senkron değil
                $fields = explode(',', $out);

                return isset($fields[3]) && (int) $fields[3] !== 3 ? 'yes' : 'no';
            } catch (Throwable) {
                continue;
            }
        }

        return 'unknown';
    }

    /** Audit F-03: doğrulanmış son yedek yaşı; üretimde BACKUP_MAX_AGE_HOURS'tan eskiyse (ya da hiç yoksa) hata. */
    private function checkBackup(bool $production): void
    {
        try {
            $age = app(BackupService::class)->lastVerifiedAgeHours();
        } catch (Throwable $e) {
            $this->add('Yedek', 'warn', 'Denetlenemedi: '.mb_substr($e->getMessage(), 0, 80));

            return;
        }

        $max = (int) config('ofisvio.backup.max_age_hours', 26);
        $encrypted = trim((string) config('ofisvio.backup.encryption_key', '')) !== '';

        if ($age === null) {
            $this->strict($production, 'Yedek', 'Doğrulanmış yedek yok — php artisan ofisvio:backup');
        } elseif ($age > $max) {
            $this->strict($production, 'Yedek', sprintf('Son doğrulanmış yedek %.0f saat önce (> %d) — zamanlayıcı/backup çalışmıyor', $age, $max));
        } else {
            $this->add('Yedek', 'ok', sprintf('son doğrulanmış yedek %.0f saat önce%s', $age, $encrypted ? '' : ' · ŞİFRESİZ (BACKUP_ENCRYPTION_KEY)'));
        }
    }

    /** Audit F-18: başarısız kuyruk işleri görünür olsun (health-alert bunu uyarıya çevirir). */
    private function checkFailedJobs(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            return;
        }

        $n = DB::table('failed_jobs')->count();
        $n === 0
            ? $this->add('Başarısız işler', 'ok', 'failed_jobs boş')
            : $this->add('Başarısız işler', 'warn', $n.' iş — php artisan queue:failed / queue:retry all');
    }

    /** Audit F-14: eski APP_KEY ile şifreli kayıt kaldıysa APP_PREVIOUS_KEYS kaldırılamaz; üretimde hata. */
    private function checkKeyRotation(bool $production): void
    {
        try {
            $pending = array_sum(app(KeyRotationService::class)->pending());
        } catch (Throwable $e) {
            $this->add('Anahtar rotasyonu', 'warn', 'Denetlenemedi: '.$e->getMessage());

            return;
        }

        $previous = array_filter((array) config('app.previous_keys'));
        $pending === 0
            ? $this->add('Anahtar rotasyonu', 'ok', $previous !== [] ? 'Bekleyen kayıt yok — APP_PREVIOUS_KEYS kaldırılabilir' : 'tüm şifreli kayıtlar mevcut anahtarla')
            : $this->strict($production, 'Anahtar rotasyonu', $pending.' kayıt eski anahtarla şifreli — php artisan ofisvio:reencrypt (APP_PREVIOUS_KEYS silinmeden)');
    }

    /** Audit F-07 (KVKK): vitrin rızaları bir yasal metin sürümüne bağlanmalı; üretimde KVKK sürümü yoksa hata. */
    private function checkLegal(bool $production): void
    {
        $site = Website::query()->where('is_default', true)->first();

        if ($site === null) {
            return; // "Varsayılan site" kontrolü zaten hata verir
        }

        try {
            $current = app(LegalDocumentService::class)->current($site, 'kvkk');
        } catch (Throwable $e) {
            $this->add('KVKK metni sürümü', 'warn', 'Denetlenemedi (migration bekliyor?): '.mb_substr($e->getMessage(), 0, 80));

            return;
        }

        $current !== null
            ? $this->add('KVKK metni sürümü', 'ok', 'v'.$current->version.' · '.$current->published_at->format('d.m.Y'))
            : $this->strict($production, 'KVKK metni sürümü', 'Yok — Ayarlar › Footer ekranında KVKK sayfasını seçip yayınlayın; rızalar metne bağlanamıyor');
    }

    /**
     * Web kurulum sihirbazı (faz 62) kapalı mı: anahtar dosyası duruyorsa uç yeniden açılabilir. Kurulum kendi
     * kapanışında dosyayı siler; elle bırakılmış bir dosya üretimde hatadır.
     */
    private function checkInstallEndpoint(bool $production): void
    {
        $gate = app(InstallGate::class);

        if (! $gate->challengeExists()) {
            $this->add('Kurulum ucu', 'ok', 'kapalı');

            return;
        }

        $this->strict($production, 'Kurulum ucu', 'AÇIK — '.$gate->challengePath().' duruyor; kurulum bittiyse bu dosyayı silin');
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
