<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Yedekleme / geri yükleme (audit F-03). Yedek = tek tar arşivi: veritabanı dökümü (sqlite dosya kopyası, mysql
 * `mysqldump`, pgsql `pg_dump`), özel depolama (KYC/sözleşme dosyaları), public medya ve `manifest.json`
 * (zaman, sürüm/commit, sürücü, dosya listesi, boyut). Arşiv sha256'sı manifest'in yanında `.sha256` dosyasında;
 * BACKUP_ENCRYPTION_KEY tanımlıysa arşiv parça parça AES-256-GCM ile şifrelenir (`.enc`) — anahtar olmadan
 * içerik okunamaz, doğrulama da anahtar ister. Hedef dizin BACKUP_PATH (varsayılan storage/app/backups); üretimde
 * bu dizin sunucu dışına (immutable/nesne depolama) senkronlanmalıdır (DEPLOY.md). Retention BACKUP_KEEP_DAYS.
 * Doğrulama gerçek açmadır: sha256 + (şifre çözme) + tar okunur + döküm/manifest tutarlılığı. Geri yükleme
 * (`ofisvio:restore --force`) uygulama bakımdayken çalışır; sqlite dosyası değiştirilir, mysql/pgsql dökümü içe
 * aktarılır, dosyalar yerine yazılır. Her adım audit'e düşer (yol/özet; içerik değil).
 */
class BackupService
{
    public const CHUNK = 1048576; // 1 MiB

    private const MAGIC = "OFVBK1\n";

    public function __construct(private readonly AuditService $audit) {}

    public function directory(): string
    {
        $dir = trim((string) config('ofisvio.backup.path', '')) ?: storage_path('app/backups');
        File::ensureDirectoryExists($dir, 0700);

        return rtrim($dir, '/\\');
    }

    /**
     * Yedek oluşturur; döner: manifest + arşiv yolu + sha256.
     *
     * @return array{name: string, path: string, sha256: string, size: int, encrypted: bool, manifest: array<string, mixed>}
     */
    public function create(bool $includeMedia = true): array
    {
        $name = 'ofisvio-'.Carbon::now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $work = $this->directory().DIRECTORY_SEPARATOR.'.work-'.$name;
        File::ensureDirectoryExists($work, 0700);

        try {
            $dump = $this->dumpDatabase($work);
            $tarPath = $work.DIRECTORY_SEPARATOR.$name.'.tar';
            $tar = new PharData($tarPath);
            $files = [];
            $tar->addFile($dump['path'], 'db/'.$dump['file']);
            $files['db/'.$dump['file']] = filesize($dump['path']);

            foreach ($this->fileRoots($includeMedia) as $prefix => $root) {
                foreach ($this->walk($root) as $file) {
                    $relative = $prefix.'/'.str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($root)), '/\\'));
                    $tar->addFile($file->getPathname(), $relative);
                    $files[$relative] = $file->getSize();
                }
            }

            $manifest = [
                'format' => 1,
                'name' => $name,
                'created_at' => Carbon::now()->toIso8601String(),
                'app_env' => (string) config('app.env'),
                'commit' => $this->commit(),
                'db_driver' => $dump['driver'],
                'db_file' => 'db/'.$dump['file'],
                'files' => $files,
                'file_count' => count($files),
                'bytes' => array_sum($files),
            ];
            $tar->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            unset($tar);

            $key = $this->key();
            $final = $this->directory().DIRECTORY_SEPARATOR.$name.($key !== null ? '.tar.enc' : '.tar');

            if ($key !== null) {
                $this->encryptFile($tarPath, $final, $key);
            } else {
                File::move($tarPath, $final);
            }

            $sha = hash_file('sha256', $final);
            File::put($final.'.sha256', $sha.'  '.basename($final)."\n");
            File::put($this->directory().DIRECTORY_SEPARATOR.$name.'.manifest.json', (string) json_encode($manifest + ['archive' => basename($final), 'sha256' => $sha, 'encrypted' => $key !== null], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } finally {
            File::deleteDirectory($work);
        }

        $this->prune();
        $this->audit->record(null, 'backup.created', 'system', null, [], ['name' => $name, 'sha256' => $sha, 'bytes' => (int) filesize($final), 'files' => count($files), 'encrypted' => $key !== null]);

        return ['name' => $name, 'path' => $final, 'sha256' => (string) $sha, 'size' => (int) filesize($final), 'encrypted' => $key !== null, 'manifest' => $manifest];
    }

    /**
     * Gerçek doğrulama: sha256, şifre çözme, tar açma, manifest ↔ içerik tutarlılığı.
     *
     * @return array{ok: bool, name: string, problems: list<string>, manifest: array<string, mixed>}
     */
    public function verify(string $name): array
    {
        $problems = [];
        $manifest = $this->manifest($name);
        $archive = $this->directory().DIRECTORY_SEPARATOR.(string) $manifest['archive'];

        if (! is_file($archive)) {
            return ['ok' => false, 'name' => $name, 'problems' => ['Arşiv dosyası yok: '.basename($archive)], 'manifest' => $manifest];
        }

        if (hash_file('sha256', $archive) !== (string) $manifest['sha256']) {
            $problems[] = 'sha256 uyuşmuyor — arşiv değişmiş ya da bozuk';

            return ['ok' => false, 'name' => $name, 'problems' => $problems, 'manifest' => $manifest];
        }

        $work = $this->directory().DIRECTORY_SEPARATOR.'.verify-'.$name;
        File::ensureDirectoryExists($work, 0700);

        try {
            $tarPath = $this->plainTar($archive, $manifest, $work);
            $tar = new PharData($tarPath);
            $inside = json_decode((string) file_get_contents('phar://'.$tarPath.'/manifest.json'), true);

            if (! is_array($inside) || ($inside['name'] ?? null) !== $name) {
                $problems[] = 'Arşiv içi manifest eksik ya da farklı yedeğe ait';
            }

            foreach ((array) ($manifest['files'] ?? []) as $file => $size) {
                if (! isset($tar[$file])) {
                    $problems[] = 'Eksik dosya: '.$file;
                } elseif ((int) $tar[$file]->getSize() !== (int) $size) {
                    $problems[] = 'Boyut farklı: '.$file;
                }
            }

            $dbFile = (string) ($manifest['db_file'] ?? '');

            if ($dbFile === '' || ! isset($tar[$dbFile]) || (int) $tar[$dbFile]->getSize() < 1) {
                $problems[] = 'Veritabanı dökümü yok ya da boş';
            }

            unset($tar);
        } catch (Throwable $e) {
            $problems[] = 'Arşiv açılamadı: '.$e->getMessage();
        } finally {
            File::deleteDirectory($work);
        }

        $ok = $problems === [];
        File::put($this->directory().DIRECTORY_SEPARATOR.$name.'.verified', ($ok ? 'ok ' : 'FAIL ').Carbon::now()->toIso8601String()."\n");
        $this->audit->record(null, 'backup.verified', 'system', null, [], ['name' => $name, 'ok' => $ok, 'problems' => $problems]);

        return ['ok' => $ok, 'name' => $name, 'problems' => $problems, 'manifest' => $manifest];
    }

    /**
     * Geri yükleme: önce doğrulama; veritabanı dökümü içe aktarılır, dosyalar yerine yazılır. Çağıran uygulamayı
     * bakıma almış olmalı (komut bunu yapar). Döner: geri yüklenen dosya sayısı.
     */
    public function restore(string $name, bool $restoreFiles = true): int
    {
        $result = $this->verify($name);

        if (! $result['ok']) {
            throw new DomainException('Yedek doğrulanamadı: '.implode('; ', $result['problems']));
        }

        $manifest = $result['manifest'];
        $archive = $this->directory().DIRECTORY_SEPARATOR.(string) $manifest['archive'];
        $work = $this->directory().DIRECTORY_SEPARATOR.'.restore-'.$name;
        File::ensureDirectoryExists($work, 0700);
        $restored = 0;

        try {
            $tarPath = $this->plainTar($archive, $manifest, $work);
            $tar = new PharData($tarPath);
            $tar->extractTo($work.DIRECTORY_SEPARATOR.'x', null, true);
            unset($tar);
            $extracted = $work.DIRECTORY_SEPARATOR.'x';

            $this->importDatabase((string) $manifest['db_driver'], $extracted.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $manifest['db_file']));

            if ($restoreFiles) {
                foreach ($this->fileRoots(true) as $prefix => $root) {
                    $src = $extracted.DIRECTORY_SEPARATOR.$prefix;

                    if (! is_dir($src)) {
                        continue;
                    }

                    File::ensureDirectoryExists($root);

                    foreach ($this->walk($src) as $file) {
                        $target = $root.DIRECTORY_SEPARATOR.ltrim(substr($file->getPathname(), strlen($src)), '/\\');
                        File::ensureDirectoryExists(dirname($target));
                        File::copy($file->getPathname(), $target);
                        $restored++;
                    }
                }
            }
        } finally {
            File::deleteDirectory($work);
        }

        $this->audit->record(null, 'backup.restored', 'system', null, [], ['name' => $name, 'files' => $restored, 'db_driver' => $manifest['db_driver']]);

        return $restored;
    }

    /** @return list<array{name: string, created_at: string, bytes: int, encrypted: bool, verified: string|null}> */
    public function list(): array
    {
        $out = [];

        foreach (glob($this->directory().DIRECTORY_SEPARATOR.'*.manifest.json') ?: [] as $path) {
            $m = json_decode((string) file_get_contents($path), true);

            if (! is_array($m)) {
                continue;
            }

            $verified = $this->directory().DIRECTORY_SEPARATOR.$m['name'].'.verified';
            $out[] = ['name' => (string) $m['name'], 'created_at' => (string) $m['created_at'], 'bytes' => (int) ($m['bytes'] ?? 0), 'encrypted' => (bool) ($m['encrypted'] ?? false), 'verified' => is_file($verified) ? trim((string) file_get_contents($verified)) : null];
        }

        usort($out, fn (array $a, array $b) => strcmp($b['created_at'], $a['created_at']));

        return $out;
    }

    /** Son doğrulanmış yedeğin yaşı (saat) — doctor. */
    public function lastVerifiedAgeHours(): ?float
    {
        foreach ($this->list() as $b) {
            if ($b['verified'] !== null && str_starts_with($b['verified'], 'ok')) {
                return Carbon::parse($b['created_at'])->diffInHours(Carbon::now(), true);
            }
        }

        return null;
    }

    /** Retention: BACKUP_KEEP_DAYS'ten eski yedekler silinir; en az BACKUP_KEEP_MIN tanesi kalır. */
    public function prune(): int
    {
        $days = max(1, (int) config('ofisvio.backup.keep_days', 30));
        $keepMin = max(1, (int) config('ofisvio.backup.keep_min', 3));
        $list = $this->list();
        $n = 0;

        foreach (array_slice($list, $keepMin) as $b) {
            if (Carbon::parse($b['created_at'])->lt(Carbon::now()->subDays($days))) {
                foreach (glob($this->directory().DIRECTORY_SEPARATOR.$b['name'].'.*') ?: [] as $f) {
                    File::delete($f);
                }
                $n++;
            }
        }

        return $n;
    }

    // ---- yardımcılar ------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function manifest(string $name): array
    {
        if (! preg_match('/^ofisvio-\d{8}-\d{6}-[a-z0-9]{6}$/', $name)) {
            throw new DomainException('Geçersiz yedek adı.');
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$name.'.manifest.json';
        $m = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($m)) {
            throw new DomainException('Yedek bulunamadı: '.$name);
        }

        return $m;
    }

    /** @return array{driver: string, path: string, file: string} */
    private function dumpDatabase(string $work): array
    {
        $driver = DB::connection()->getDriverName();
        $config = (array) config('database.connections.'.config('database.default'));

        switch ($driver) {
            case 'sqlite':
                $source = (string) ($config['database'] ?? '');

                if ($source === ':memory:' || ! is_file($source)) {
                    throw new DomainException('SQLite dosyası bulunamadı ('.$source.'); bellek içi veritabanı yedeklenemez.');
                }

                $path = $work.DIRECTORY_SEPARATOR.'database.sqlite';
                if (DB::connection()->getDatabaseName() === $source) {
                    try {
                        DB::statement('PRAGMA wal_checkpoint(FULL)'); // WAL'deki yazımlar ana dosyaya insin
                    } catch (Throwable) {
                        // salt okunur/ kilitli bağlantı: kopya yine alınır (WAL dosyası da kopyalanmaz — sonraki yedekte tamamlanır)
                    }
                }

                File::copy($source, $path);

                return ['driver' => 'sqlite', 'path' => $path, 'file' => 'database.sqlite'];
            case 'mysql':
            case 'mariadb':
                $path = $work.DIRECTORY_SEPARATOR.'database.sql';
                $this->run(['mysqldump', '--single-transaction', '--quick', '--routines', '--triggers', '-h', (string) ($config['host'] ?? '127.0.0.1'), '-P', (string) ($config['port'] ?? 3306), '-u', (string) ($config['username'] ?? ''), (string) ($config['database'] ?? '')], ['MYSQL_PWD' => (string) ($config['password'] ?? '')], $path);

                return ['driver' => 'mysql', 'path' => $path, 'file' => 'database.sql'];
            case 'pgsql':
                $path = $work.DIRECTORY_SEPARATOR.'database.sql';
                $this->run(['pg_dump', '--no-owner', '--no-privileges', '-h', (string) ($config['host'] ?? '127.0.0.1'), '-p', (string) ($config['port'] ?? 5432), '-U', (string) ($config['username'] ?? ''), (string) ($config['database'] ?? '')], ['PGPASSWORD' => (string) ($config['password'] ?? '')], $path);

                return ['driver' => 'pgsql', 'path' => $path, 'file' => 'database.sql'];
        }

        throw new DomainException('Desteklenmeyen veritabanı sürücüsü: '.$driver);
    }

    private function importDatabase(string $driver, string $dump): void
    {
        $config = (array) config('database.connections.'.config('database.default'));
        $current = DB::connection()->getDriverName();

        if (($driver === 'mysql' && ! in_array($current, ['mysql', 'mariadb'], true)) || ($driver !== 'mysql' && $driver !== $current)) {
            throw new DomainException("Yedek {$driver} dökümü; çalışan bağlantı {$current}.");
        }

        switch ($driver) {
            case 'sqlite':
                $target = (string) ($config['database'] ?? '');
                DB::disconnect();
                File::copy($dump, $target);
                DB::reconnect();

                return;
            case 'mysql':
                $this->run(['mysql', '-h', (string) ($config['host'] ?? '127.0.0.1'), '-P', (string) ($config['port'] ?? 3306), '-u', (string) ($config['username'] ?? ''), (string) ($config['database'] ?? '')], ['MYSQL_PWD' => (string) ($config['password'] ?? '')], null, $dump);

                return;
            case 'pgsql':
                $this->run(['psql', '-v', 'ON_ERROR_STOP=1', '-h', (string) ($config['host'] ?? '127.0.0.1'), '-p', (string) ($config['port'] ?? 5432), '-U', (string) ($config['username'] ?? ''), '-d', (string) ($config['database'] ?? '')], ['PGPASSWORD' => (string) ($config['password'] ?? '')], null, $dump);

                return;
        }
    }

    /** @param  array<int, string>  $cmd  @param  array<string, string>  $env */
    private function run(array $cmd, array $env, ?string $stdoutFile, ?string $stdinFile = null): void
    {
        $process = new Process($cmd, null, $env, $stdinFile !== null ? fopen($stdinFile, 'r') : null, 3600);
        $out = $stdoutFile !== null ? fopen($stdoutFile, 'w') : null;
        $process->run(function (string $type, string $buffer) use ($out) {
            if ($type === Process::OUT && $out !== null) {
                fwrite($out, $buffer);
            }
        });

        if ($out !== null) {
            fclose($out);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException($cmd[0].' başarısız: '.mb_substr(trim($process->getErrorOutput()), 0, 200)); // parola env'de, log'a girmez
        }
    }

    /** @return array<string, string> önek => kök dizin */
    private function fileRoots(bool $includeMedia): array
    {
        $roots = ['private' => storage_path('app/private')];

        if ($includeMedia) {
            $roots['public'] = storage_path('app/public');
        }

        return array_filter($roots, fn (string $dir) => is_dir($dir));
    }

    /** @return iterable<SplFileInfo> */
    private function walk(string $root): iterable
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getFilename() !== '.gitignore') {
                yield $file;
            }
        }
    }

    private function key(): ?string
    {
        $raw = trim((string) config('ofisvio.backup.encryption_key', ''));

        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);

            return $decoded !== false && strlen($decoded) === 32 ? $decoded : hash('sha256', $raw, true);
        }

        return hash('sha256', $raw, true);
    }

    /** Parça parça AES-256-GCM: [MAGIC][chunk: len(4) iv(12) tag(16) cipher]; sıra numarası AAD'de (parça değiştirme/yeniden sıralama fark edilir). */
    private function encryptFile(string $source, string $target, string $key): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($target, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Yedek dosyası açılamadı.');
        }

        fwrite($out, self::MAGIC);
        $seq = 0;

        while (! feof($in)) {
            $plain = fread($in, self::CHUNK);

            if ($plain === false || $plain === '') {
                break;
            }

            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, (string) $seq, 16);

            if ($cipher === false) {
                throw new RuntimeException('Şifreleme başarısız.');
            }

            fwrite($out, pack('N', strlen($cipher)).$iv.$tag.$cipher);
            $seq++;
        }

        fclose($in);
        fclose($out);
        File::delete($source);
    }

    private function decryptFile(string $source, string $target, string $key): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($target, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Yedek dosyası açılamadı.');
        }

        if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new RuntimeException('Şifreli yedek başlığı tanınmadı.');
        }

        $seq = 0;

        while (! feof($in)) {
            $head = fread($in, 4);

            if ($head === false || strlen($head) < 4) {
                break;
            }

            $len = (int) unpack('N', $head)[1];
            $iv = (string) fread($in, 12);
            $tag = (string) fread($in, 16);
            $cipher = (string) fread($in, $len);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, (string) $seq);

            if ($plain === false) {
                throw new RuntimeException('Şifre çözme başarısız (yanlış anahtar ya da bozuk parça #'.$seq.').');
            }

            fwrite($out, $plain);
            $seq++;
        }

        fclose($in);
        fclose($out);
    }

    /** @param  array<string, mixed>  $manifest */
    private function plainTar(string $archive, array $manifest, string $work): string
    {
        if (! ($manifest['encrypted'] ?? false)) {
            $copy = $work.DIRECTORY_SEPARATOR.$manifest['name'].'.tar';
            File::copy($archive, $copy);

            return $copy;
        }

        $key = $this->key();

        if ($key === null) {
            throw new DomainException('Yedek şifreli; BACKUP_ENCRYPTION_KEY tanımlı değil.');
        }

        $plain = $work.DIRECTORY_SEPARATOR.$manifest['name'].'.tar';
        $this->decryptFile($archive, $plain, $key);

        return $plain;
    }

    private function commit(): ?string
    {
        $head = base_path('.git/HEAD');

        if (! is_file($head)) {
            return null;
        }

        $ref = trim((string) file_get_contents($head));

        if (str_starts_with($ref, 'ref: ')) {
            $file = base_path('.git/'.substr($ref, 5));

            return is_file($file) ? substr(trim((string) file_get_contents($file)), 0, 12) : null;
        }

        return substr($ref, 0, 12);
    }
}
