<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Geri yükleme (audit F-03): önce doğrulama; --force olmadan yalnız doğrular. Uygulama bakıma alınır, veritabanı
 * dökümü içe aktarılır, dosyalar yerine yazılır, doctor koşar; doctor geçmezse bakım modu AÇIK kalır (fail-closed).
 */
class RestoreCommand extends Command
{
    protected $signature = 'ofisvio:restore {name : Yedek adı (ofisvio:backup --list)} {--force : Gerçekten geri yükle} {--no-files : Yalnız veritabanı}';

    protected $description = 'Yedeği doğrular ve --force ile geri yükler (bakım modu + doctor kapısı).';

    public function handle(BackupService $backups): int
    {
        $name = (string) $this->argument('name');

        try {
            $verify = $backups->verify($name);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $verify['ok']) {
            $this->error('Yedek doğrulanamadı: '.implode('; ', $verify['problems']));

            return self::FAILURE;
        }

        $this->info('Doğrulama OK: '.$name.' ('.$verify['manifest']['created_at'].', '.$verify['manifest']['db_driver'].')');

        if (! $this->option('force')) {
            $this->line('Geri yüklemek için --force ekleyin (uygulama bakıma alınır, mevcut veri ÜZERİNE yazılır).');

            return self::SUCCESS;
        }

        Artisan::call('down', ['--retry' => 60]);
        $this->warn('Bakım modu açıldı.');

        try {
            $files = $backups->restore($name, ! $this->option('no-files'));
            $this->info('Veritabanı geri yüklendi; '.$files.' dosya yazıldı.');
        } catch (Throwable $e) {
            $this->error('Geri yükleme başarısız: '.$e->getMessage().' — bakım modu AÇIK bırakıldı.');

            return self::FAILURE;
        }

        if (Artisan::call('ofisvio:doctor') !== 0) {
            $this->error('Doctor geçmedi — bakım modu AÇIK bırakıldı; çıktıyı inceleyin (php artisan ofisvio:doctor).');

            return self::FAILURE;
        }

        Artisan::call('up');
        $this->info('Doctor OK; bakım modu kapatıldı.');

        return self::SUCCESS;
    }
}
