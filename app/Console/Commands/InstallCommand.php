<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Kurulum sihirbazı — CLI (audit F-17). Her domain/sunucuya bağımsız kurulum: ortam ön kontrolü (PHP, uzantılar,
 * yazılabilir dizinler, APP_KEY, veritabanı) → migration → referans veri → storage bağlantısı → env tabanlı hesaplar
 * → kilit dosyası (`storage/app/.installed`) → doctor. Kilit varken yeniden kurulum reddedilir (`--upgrade` yalnız
 * migration + referans veri + doctor koşar). Web tabanlı /install ucu YOKTUR: kurulum sunucuya erişimi olan
 * operatörün işidir; dışarıdan tetiklenecek bir yüzey açılmaz.
 */
class InstallCommand extends Command
{
    public const LOCK = 'app/.installed';

    public const MIN_PHP = '8.3.0';

    public const EXTENSIONS = ['mbstring', 'openssl', 'pdo', 'json', 'fileinfo', 'gd', 'intl', 'zlib', 'ctype', 'tokenizer', 'xml'];

    protected $signature = 'ofisvio:install {--upgrade : Kurulu sistemde migration + referans veri + doctor} {--check : Yalnız ön kontrol}';

    protected $description = 'Kurulum: ön kontrol, migration, referans veri, storage bağlantısı, hesaplar, kilit, doctor.';

    public function handle(): int
    {
        $lock = storage_path(self::LOCK);
        $installed = is_file($lock);

        if (! $this->preflight()) {
            $this->error('Ön kontrol başarısız; kurulum durduruldu.');

            return self::FAILURE;
        }

        if ($this->option('check')) {
            $this->info('Ön kontrol tamam.'.($installed ? ' (Kurulu: '.trim((string) file_get_contents($lock)).')' : ''));

            return self::SUCCESS;
        }

        if ($installed && ! $this->option('upgrade')) {
            $this->error('Sistem zaten kurulu ('.trim((string) file_get_contents($lock)).'). Güncelleme için --upgrade; sıfırdan kurulum için kilidi bilinçli olarak silin.');

            return self::FAILURE;
        }

        $this->step('Migration', fn () => Artisan::call('migrate', ['--force' => true]));
        $this->step('Referans veri (roller, hizmet kataloğu, site, bloklar, bildirim kuralları)', fn () => Artisan::call('db:seed', ['--force' => true]));

        if (! $installed) {
            $this->step('Storage bağlantısı', fn () => Artisan::call('storage:link', ['--force' => true]));
            $this->step('Hesaplar (OFISVIO_ADMIN_* env)', fn () => Artisan::call('ofisvio:bootstrap-accounts'));
            File::put($lock, now()->toIso8601String().' '.(string) config('app.version', '').' '.(string) config('app.url')."\n");
            $this->info('Kilit yazıldı: '.$lock);
        }

        $this->line('');
        $code = Artisan::call('ofisvio:doctor');
        $this->line(Artisan::output());

        if ($code !== 0) {
            $this->error('Doctor hata verdi; kurulum tamamlandı ama üretime açılmaz (yukarıdaki satırları giderin).');

            return self::FAILURE;
        }

        $this->info($installed ? 'Güncelleme tamam.' : 'Kurulum tamam.');

        return self::SUCCESS;
    }

    private function preflight(): bool
    {
        $ok = true;
        $row = function (bool $pass, string $name, string $note) use (&$ok): void {
            $this->line(($pass ? '  ✓ ' : '  ✗ ').$name.($note !== '' ? ' — '.$note : ''));
            $ok = $ok && $pass;
        };

        $row(version_compare(PHP_VERSION, self::MIN_PHP, '>='), 'PHP', PHP_VERSION.' (en az '.self::MIN_PHP.')');

        foreach (self::EXTENSIONS as $ext) {
            $row(extension_loaded($ext), 'ext-'.$ext, extension_loaded($ext) ? '' : 'yüklü değil');
        }

        $row(trim((string) config('app.key')) !== '', 'APP_KEY', trim((string) config('app.key')) !== '' ? '' : 'php artisan key:generate');
        $row(config('app.env') !== 'production' || ! config('app.debug'), 'APP_DEBUG', config('app.debug') ? 'açık (üretimde kapalı olmalı)' : 'kapalı');

        foreach ([storage_path(), storage_path('app'), storage_path('logs'), storage_path('framework'), base_path('bootstrap/cache')] as $dir) {
            $row(is_dir($dir) && is_writable($dir), 'Yazılabilir: '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $dir), is_writable($dir) ? '' : 'izin yok');
        }

        try {
            DB::connection()->getPdo();
            $row(true, 'Veritabanı', DB::connection()->getDriverName());
        } catch (Throwable $e) {
            $row(false, 'Veritabanı', mb_substr($e->getMessage(), 0, 120));
        }

        return $ok;
    }

    private function step(string $label, callable $fn): void
    {
        $this->line('→ '.$label);
        $code = (int) $fn();
        $out = trim(Artisan::output());

        if ($out !== '') {
            $this->line('  '.str_replace("\n", "\n  ", $out));
        }

        if ($code !== 0) {
            throw new \RuntimeException($label.' başarısız (kod '.$code.').');
        }
    }
}
