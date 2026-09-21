<?php

namespace App\Console\Commands;

use App\Services\RetentionService;
use Illuminate\Console\Command;

/**
 * KVKK saklama / imha koşusu (audit F-09): süresi dolan vitrin kayıtlarını anonimleştirir, eski KYC dosyalarını siler.
 * Süreler Ayarlar › Gizlilik & saklama; 0 = kapalı. Zamanlayıcı günlük çalıştırır; --dry-run yalnız sayar.
 */
class RetentionCommand extends Command
{
    protected $signature = 'ofisvio:retention {--dry-run : Yalnız sayar, değiştirmez}';

    protected $description = 'KVKK saklama süreleri dolan kayıtları anonimleştirir / eski KYC dosyalarını imha eder.';

    public function handle(RetentionService $retention): int
    {
        $dry = (bool) $this->option('dry-run');
        $counts = $retention->run($dry);

        foreach ($counts as $key => $n) {
            $this->line(sprintf('%-24s %d%s', $key, $n, $dry ? ' (dry-run)' : ''));
        }

        $this->info(($dry ? 'Sayım: ' : 'İşlendi: ').array_sum($counts).' kayıt');

        return self::SUCCESS;
    }
}
