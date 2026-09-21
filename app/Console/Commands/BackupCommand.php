<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Yedek al + doğrula (audit F-03). Zamanlayıcı günlük; elle: php artisan ofisvio:backup. Doğrulama yedeği gerçekten
 * açar (sha256, şifre çözme, tar, manifest). --list mevcut yedekleri gösterir; --verify=<ad> yalnız doğrular.
 */
class BackupCommand extends Command
{
    protected $signature = 'ofisvio:backup {--list : Yedekleri listele} {--verify= : Yalnız bu yedeği doğrula} {--no-media : Public medyayı dahil etme}';

    protected $description = 'Veritabanı + özel depolama + medya yedeği alır ve doğrular; süresi dolanları budar.';

    public function handle(BackupService $backups): int
    {
        if ($this->option('list')) {
            foreach ($backups->list() as $b) {
                $this->line(sprintf('%-36s %s  %8.1f MB  %s  %s', $b['name'], $b['created_at'], $b['bytes'] / 1048576, $b['encrypted'] ? 'şifreli' : 'açık', $b['verified'] ?? 'doğrulanmadı'));
            }

            return self::SUCCESS;
        }

        if ($this->option('verify') !== null) {
            try {
                return $this->report($backups->verify((string) $this->option('verify')));
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        try {
            $created = $backups->create(! $this->option('no-media'));
        } catch (Throwable $e) {
            $this->error('Yedek alınamadı: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Yedek: %s (%.1f MB, %s) sha256=%s', $created['name'], $created['size'] / 1048576, $created['encrypted'] ? 'şifreli' : 'AÇIK — BACKUP_ENCRYPTION_KEY tanımlayın', $created['sha256']));

        return $this->report($backups->verify($created['name']));
    }

    /** @param  array{ok: bool, name: string, problems: list<string>}  $result */
    private function report(array $result): int
    {
        if ($result['ok']) {
            $this->info('Doğrulama: '.$result['name'].' OK');

            return self::SUCCESS;
        }

        $this->error('Doğrulama BAŞARISIZ: '.$result['name'].' — '.implode('; ', $result['problems']));

        return self::FAILURE;
    }
}
