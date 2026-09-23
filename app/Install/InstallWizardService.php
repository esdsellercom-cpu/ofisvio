<?php

namespace App\Install;

use App\Console\Commands\InstallCommand;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AuditService;
use DomainException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Throwable;

/**
 * Web kurulum sihirbazının iş katmanı (faz 62). HTTP'den bağımsızdır: request/session okumaz, controller yalnız
 * doğrulanmış diziler geçirir.
 *
 * Ağır adımlar (migration, referans veri) kendi isteklerinde ve tek uçuş kilidi altında koşar; her adım
 * InstallGate durum dosyasına işlenir, böylece zaman aşımında operatör kaldığı yerden devam eder. Sırlar
 * (veritabanı şifresi, yönetici şifresi) hiçbir log/audit/ekran çıktısına yazılmaz.
 */
final class InstallWizardService
{
    /** Ağır adımın kilidi bu süre sonra bayat sayılır (saniye). */
    public const STALE_LOCK_SECONDS = 900;

    public function __construct(
        private readonly InstallGate $gate,
        private readonly EnvWriter $env,
        private readonly AuditService $audit,
    ) {}

    /**
     * Ortam ön kontrolü — ofisvio:install --check ile aynı ölçüt (tek kaynak: InstallCommand sabitleri).
     *
     * @return array{ready: bool, rows: array<int, array{name: string, ok: bool, note: string, blocking: bool}>}
     */
    public function requirements(): array
    {
        $rows = [];
        $phpOk = version_compare(PHP_VERSION, InstallCommand::MIN_PHP, '>=');
        $rows[] = ['name' => 'PHP sürümü', 'ok' => $phpOk, 'note' => PHP_VERSION.($phpOk ? '' : ' — en az '.InstallCommand::MIN_PHP.' gerekiyor'), 'blocking' => true];

        $missing = array_values(array_filter(InstallCommand::EXTENSIONS, fn (string $e) => ! extension_loaded($e)));
        $rows[] = ['name' => 'PHP uzantıları', 'ok' => $missing === [], 'note' => $missing === [] ? implode(', ', InstallCommand::EXTENSIONS) : 'eksik: '.implode(', ', $missing), 'blocking' => true];

        $writable = ['storage' => storage_path(), 'storage/framework' => storage_path('framework'), 'storage/logs' => storage_path('logs'), 'bootstrap/cache' => base_path('bootstrap/cache')];

        foreach ($writable as $label => $path) {
            $ok = is_dir($path) && is_writable($path);
            $rows[] = ['name' => 'Yazılabilir: '.$label, 'ok' => $ok, 'note' => $ok ? '' : 'dizin yazılabilir değil (chmod 755 ve doğru sahiplik)', 'blocking' => true];
        }

        $envOk = $this->env->writable();
        $rows[] = ['name' => 'Yapılandırma dosyası (.env)', 'ok' => $envOk, 'note' => $envOk ? ($this->env->exists() ? 'yazılabilir' : 'örnekten oluşturulacak') : 'yazılabilir değil (dosya izni 644 ya da 640 olmalı)', 'blocking' => true];

        $key = (string) Config::get('app.key');
        $rows[] = ['name' => 'Uygulama anahtarı (APP_KEY)', 'ok' => $key !== '', 'note' => $key !== '' ? 'hazır' : 'kurulum sırasında üretilecek', 'blocking' => false];

        $link = public_path('storage');
        $rows[] = ['name' => 'Medya bağlantısı (public/storage)', 'ok' => file_exists($link), 'note' => file_exists($link) ? 'var' : 'kurulum sonunda denenecek; sunucu symlink desteklemiyorsa elle oluşturulur', 'blocking' => false];

        $ready = array_filter($rows, fn (array $r) => $r['blocking'] && ! $r['ok']) === [];

        return ['ready' => $ready, 'rows' => $rows];
    }

    /** APP_KEY yalnız BOŞKEN üretilir; dolu anahtara asla dokunulmaz (şifreli alanlar okunamaz hale gelirdi). */
    public function ensureAppKey(): void
    {
        if ((string) Config::get('app.key') !== '') {
            return;
        }

        $this->env->ensureExists();
        $key = 'base64:'.base64_encode(random_bytes(32));
        // APP_DEBUG aynı yazımda kapatılır: .env.example true ile gelir ve kurulum bitene kadar /install DIŞINDAKİ
        // her adres yakalanmamış istisnada .env değerlerini basardı.
        $this->env->write(['APP_KEY' => $key, 'APP_DEBUG' => 'false']);
        Config::set(['app.key' => $key, 'app.debug' => false]);
    }

    /**
     * Veritabanı bağlantısını .env'e YAZMADAN ÖNCE dener (yanlış bilgiyle yazılan .env siteyi tamamen kapatır).
     * Hata ayrıntısı (sürücü metni, host) ekrana basılmaz; yalnız kurulum günlüğüne sırsız yazılır.
     *
     * @param  array<string, string>  $db
     */
    public function probeDatabase(array $db): void
    {
        $driver = $db['DB_CONNECTION'] ?? 'mysql';

        try {
            $pdo = $driver === 'sqlite'
                ? new PDO('sqlite:'.($db['DB_DATABASE'] ?? ''))
                : new PDO($this->dsn($driver, $db), $db['DB_USERNAME'] ?? '', $db['DB_PASSWORD'] ?? '', [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            $this->log('Veritabanı bağlantısı başarısız: '.$e->getMessage(), ['driver' => $driver, 'database' => $db['DB_DATABASE'] ?? '']);

            throw new DomainException('Veritabanına bağlanılamadı. Sunucu adresi, veritabanı adı, kullanıcı ve şifreyi kontrol edin; kullanıcının bu veritabanında tam yetkisi olmalı.');
        }

        $this->assertEmptyDatabase($pdo, $driver);
    }

    /**
     * Form alanları → .env anahtarları (eşleme burada; controller yalnız doğrulanmış diziyi geçirir).
     *
     * @param  array<string, string>  $form  connection, host, port, database, username, password
     */
    public function saveDatabase(array $form): void
    {
        $env = $this->databaseEnv($form);
        $this->assertSafeTarget($env);
        $this->probeDatabase($env);
        $this->env->write($env);
        $this->gate->markStep('database');
    }

    /**
     * @param  array<string, string>  $form  url, timezone, trusted_proxies, installation_id, mail_*
     * @param  bool  $requestIsSecure  isteğin kendisi HTTPS mi (güvenli çerez bayrağı buna bakar)
     */
    public function saveSite(array $form, bool $requestIsSecure = false): void
    {
        $url = rtrim(trim($form['url']), '/');
        // Çerez bayrağı yalnız istek GERÇEKTEN güvenliyken açılır: https adres yazıp http üzerinden devam eden
        // operatörün oturumu bir sonraki adımda kopardı. Yönetici adımı HTTPS zorunlu olduğu için orada kesinleşir.
        $secure = str_starts_with($url, 'https://') && $requestIsSecure;

        $env = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $url,
            'APP_TIMEZONE' => $form['timezone'],
            'SESSION_SECURE_COOKIE' => $secure ? 'true' : 'false',
            'TRUSTED_PROXIES' => trim($form['trusted_proxies'] ?? ''),
            'OFISVIO_INSTALLATION_ID' => trim($form['installation_id'] ?? ''),
            'MAIL_MAILER' => $form['mail_mailer'] ?? 'log',
        ];

        if (($form['mail_mailer'] ?? 'log') === 'smtp') {
            $env += [
                'MAIL_HOST' => trim($form['mail_host'] ?? ''),
                'MAIL_PORT' => trim($form['mail_port'] ?? '587'),
                'MAIL_USERNAME' => trim($form['mail_username'] ?? ''),
                'MAIL_PASSWORD' => (string) ($form['mail_password'] ?? ''),
                'MAIL_SCHEME' => trim($form['mail_port'] ?? '587') === '465' ? 'smtps' : 'smtp',
                'MAIL_FROM_ADDRESS' => trim($form['mail_from'] ?? ''),
            ];
        }

        $this->env->write($env);
        $this->gate->markStep('site');
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, string>
     */
    private function databaseEnv(array $form): array
    {
        if (($form['connection'] ?? 'mysql') === 'sqlite') {
            return ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => trim($form['database'])];
        }

        return [
            'DB_CONNECTION' => $form['connection'],
            'DB_HOST' => trim($form['host']),
            'DB_PORT' => trim($form['port']),
            'DB_DATABASE' => trim($form['database']),
            'DB_USERNAME' => trim($form['username']),
            'DB_PASSWORD' => (string) ($form['password'] ?? ''),
        ];
    }

    /** Veritabanı tabloları — kendi isteğinde, tek uçuş kilidiyle. */
    public function migrate(): string
    {
        return $this->heavy('migrate', fn () => $this->artisan('migrate', ['--force' => true]));
    }

    /** Referans veri (roller, izin matrisi, hizmet kataloğu, varsayılan site). */
    public function seed(): string
    {
        return $this->heavy('seed', fn () => $this->artisan('db:seed', ['--force' => true]));
    }

    /**
     * Yönetici hesabı — doğrudan yazılır. ofisvio:bootstrap-accounts ÇAĞRILMAZ: o komut şifreyi config'ten okur,
     * config aynı istekte yeniden yüklenmez ve hesabı açmadan başarı döndürebilirdi (sessiz başarısızlık).
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function createAdmin(array $data): void
    {
        if (! PasswordPolicy::ok($data['password'])) {
            throw new DomainException('Şifre politikaya uymuyor: '.PasswordPolicy::DESCRIPTION.'.');
        }

        $role = Role::query()->where('name', 'super_admin')->where('type', 'internal')->first();

        if ($role === null) {
            throw new DomainException('Roller bulunamadı — önce "Referans veri" adımını tamamlayın.');
        }

        $email = Str::lower(trim($data['email']));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::create(['name' => trim($data['name']), 'email' => $email, 'password' => $data['password']]);
            $user->forceFill(['email_verified_at' => now()])->save(); // operatör sunucuda: adres doğrulanmış sayılır (fillable değil)
        } elseif (($this->gate->state()['admin_email'] ?? null) === $email) {
            // Aynı adımın tekrarı (operatör şifreyi yeniden giriyor): kendi açtığı hesabın şifresi güncellenir.
            $user->forceFill(['name' => trim($data['name'])])->save();
            $user->password = $data['password'];
            $user->save();
        } else {
            throw new DomainException('Bu e-posta zaten kayıtlı. Farklı bir adres girin.');
        }

        $this->gate->remember(['admin_email' => $email]);

        UserRole::query()->firstOrCreate(
            ['user_id' => $user->id, 'role_id' => $role->id, 'company_id' => null, 'organization_id' => null, 'location_id' => null],
            ['status' => 'active'],
        );

        if (User::query()->where('email', $email)->doesntExist()) {
            throw new DomainException('Yönetici hesabı yazılamadı; veritabanı yetkilerini kontrol edin.');
        }

        // Yönetici adımına yalnız HTTPS üzerinden gelinir: güvenli çerez bayrağı burada kesinleşir.
        $this->env->write(['SESSION_SECURE_COOKIE' => 'true']);
        $this->gate->markStep('admin');
    }

    /**
     * Kapanış: medya bağlantısı (zorunlu değil) → önbellek temizliği → kilit + anahtar dosyasının silinmesi →
     * audit satırı → doctor özeti. Bu noktadan sonra /install 404'tür.
     *
     * @return array{notes: array<int, string>, doctor: array<int, array{name: string, level: string, note: string}>}
     */
    public function finalize(): array
    {
        $notes = [];

        try {
            $this->artisan('storage:link', ['--force' => true]);
        } catch (Throwable) {
            $notes[] = 'Medya bağlantısı (public/storage) oluşturulamadı — paylaşımlı hostingde symlink kapalı olabilir. Panelden yüklenen görseller görünmezse hosting desteğinden "public/storage → storage/app/public" bağlantısını isteyin.';
        }

        try {
            $this->artisan('optimize:clear');
        } catch (Throwable) {
            $notes[] = 'Önbellek temizlenemedi; kurulum yine de tamamlandı.';
        }

        // Audit satırı ÖNCE: kilit yazıldıktan sonra oluşacak bir hata kurulumu yarıda bırakırdı.
        $this->audit->record(null, 'install.completed', 'system:install', null, [], ['url' => (string) Config::get('app.url'), 'env' => (string) Config::get('app.env')]);

        foreach ($this->gate->complete() as $leftover) {
            $notes[] = 'Şu dosya silinemedi, elle silin: '.$leftover.' — durduğu sürece kurulum ucu yeniden açılabilir (ofisvio:doctor bunu üretimde hata sayar).';
        }

        return ['notes' => $notes, 'doctor' => $this->doctor()];
    }

    /** @return array<int, array{name: string, level: string, note: string}> */
    public function doctor(): array
    {
        try {
            Artisan::call('ofisvio:doctor', ['--json' => true]);
            $decoded = json_decode(Artisan::output(), true);
        } catch (Throwable $e) {
            $this->log('Doctor çalıştırılamadı: '.$e->getMessage());

            return [['name' => 'Sistem denetimi', 'level' => 'warn', 'note' => 'Denetim çalıştırılamadı; sunucuda "php artisan ofisvio:doctor" ile bakın.']];
        }

        if (! is_array($decoded) || ! is_array($decoded['rows'] ?? null)) {
            return [];
        }

        /** @var array<int, array{name: string, level: string, note: string}> $rows */
        $rows = $decoded['rows'];

        return $rows;
    }

    /** @param  array<string, mixed>  $options */
    private function artisan(string $command, array $options = []): string
    {
        try {
            $code = Artisan::call($command, $options);
            $output = Artisan::output(); // tampon HEMEN okunur; sonraki çağrı sıfırlar
        } catch (Throwable $e) {
            $this->log($command.' istisna attı: '.$e->getMessage());

            throw new DomainException('"'.$command.'" adımı tamamlanamadı. Ayrıntı: storage/logs/install.log');
        }

        if ($code !== 0) {
            $this->log($command.' hata verdi: '.$output);

            throw new DomainException('"'.$command.'" adımı tamamlanamadı. Ayrıntı: storage/logs/install.log');
        }

        return $output;
    }

    /** @param  callable(): string  $work */
    private function heavy(string $step, callable $work): string
    {
        $path = storage_path(InstallGate::RUNNING);
        File::ensureDirectoryExists(dirname($path));

        // Bayat kilit (zaman aşımına uğramış istek) temizlenir; ardından kilit ATOMİK olarak oluşturulur:
        // 'x' modu dosya varsa başarısız olur, iki eşzamanlı istek aynı adımı başlatamaz.
        if (is_file($path) && (time() - (int) filemtime($path)) >= self::STALE_LOCK_SECONDS) {
            File::delete($path);
        }

        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new DomainException('Bir kurulum adımı şu anda çalışıyor. Bitmesini bekleyin; sayfa zaman aşımına uğradıysa '.self::STALE_LOCK_SECONDS.' saniye sonra yeniden deneyin ya da şu dosyayı silin: '.$path);
        }

        $owner = bin2hex(random_bytes(8));
        fwrite($handle, $step.' '.$owner."\n");
        fclose($handle);
        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $output = $work();
            $this->gate->markStep($step);

            return $output;
        } finally {
            // Yalnız KENDİ kilidimizi sileriz: bayat kilit temizliği başka bir isteğin süren kilidini kaldırabilirdi.
            if (is_file($path) && str_contains((string) file_get_contents($path), $owner)) {
                File::delete($path);
            }
        }
    }

    /**
     * Hedef doğrulaması: sqlite dosyası web kökünün altına açılamaz (dışarıdan indirilebilirdi), sunucu ve
     * veritabanı adı DSN'e ek parametre enjekte edemez.
     *
     * @param  array<string, string>  $db
     */
    private function assertSafeTarget(array $db): void
    {
        if (($db['DB_CONNECTION'] ?? '') === 'sqlite') {
            $file = $db['DB_DATABASE'] ?? '';
            $directory = dirname($file);

            if ($file === '' || ! is_dir($directory) || ! is_writable($directory)) {
                throw new DomainException('SQLite dosyasının klasörü yok ya da yazılabilir değil.');
            }

            $public = realpath(public_path()) ?: public_path();
            $resolved = realpath($directory) ?: $directory;

            if (str_starts_with($resolved, $public)) {
                throw new DomainException('SQLite dosyası web kökünün altında olamaz (dışarıdan indirilebilir). storage/ altında bir yol verin.');
            }

            return;
        }

        foreach (['DB_HOST' => '/^[A-Za-z0-9._\-\[\]:]+$/', 'DB_DATABASE' => '/^[A-Za-z0-9._\-]+$/', 'DB_PORT' => '/^[0-9]{1,5}$/'] as $key => $pattern) {
            if (preg_match($pattern, (string) ($db[$key] ?? '')) !== 1) {
                throw new DomainException($key.' değeri geçersiz karakter içeriyor.');
            }
        }
    }

    /** @param  array<string, string>  $db */
    private function dsn(string $driver, array $db): string
    {
        $host = $db['DB_HOST'] ?? '127.0.0.1';
        $port = $db['DB_PORT'] ?? ($driver === 'pgsql' ? '5432' : '3306');
        $name = $db['DB_DATABASE'] ?? '';

        return $driver.':host='.$host.';port='.$port.';dbname='.$name;
    }

    /**
     * Hedef veritabanı boş mu — tablo sayısıyla ölçülür (yalnız `users` sorgusu, yetki hatasında "temiz" der ve
     * dolu bir veritabanının üstüne migration koşardı). Kendi yarım kalan kurulumumuz istisnadır: tabloları bu
     * sihirbaz oluşturduysa devam edilir.
     */
    private function assertEmptyDatabase(PDO $pdo, string $driver): void
    {
        if ($this->gate->stepDone('migrate')) {
            return; // yarıda kalan kendi kurulumumuza devam
        }

        $sql = match ($driver) {
            'sqlite' => "select count(*) from sqlite_master where type = 'table' and name not like 'sqlite_%'",
            'pgsql' => 'select count(*) from information_schema.tables where table_schema = current_schema()',
            default => 'select count(*) from information_schema.tables where table_schema = database()',
        };

        try {
            $statement = $pdo->query($sql);
            $tables = $statement === false ? null : (int) $statement->fetchColumn();
        } catch (PDOException $e) {
            $this->log('Boşluk denetimi yapılamadı: '.$e->getMessage());
            $tables = null;
        }

        if ($tables === null) {
            throw new DomainException('Veritabanının boş olduğu doğrulanamadı (kullanıcının tablo listesini okuma yetkisi yok). Kullanıcıya bu veritabanında tüm yetkileri verin.');
        }

        if ($tables > 0) {
            throw new DomainException('Bu veritabanında zaten tablolar var. Veri kaybını önlemek için kurulum durduruldu: boş bir veritabanı kullanın; var olan kurulumun güncellemesi sunucuda "./install.sh --upgrade" ile yapılır.');
        }
    }

    /** @param  array<string, string>  $context */
    private function log(string $message, array $context = []): void
    {
        $line = date('c').' '.$message.($context === [] ? '' : ' '.(string) json_encode($context, JSON_UNESCAPED_UNICODE)).PHP_EOL;
        $path = storage_path('logs/install.log');

        try {
            File::ensureDirectoryExists(dirname($path));
            File::append($path, $line);
        } catch (Throwable) {
            // günlük yazılamıyorsa kurulum durmaz; ekranda anlaşılır mesaj zaten var
        }
    }
}
