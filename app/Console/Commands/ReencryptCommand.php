<?php

namespace App\Console\Commands;

use App\Services\KeyRotationService;
use Illuminate\Console\Command;

/**
 * APP_KEY rotasyonu (audit F-14): APP_PREVIOUS_KEYS'te eski anahtar varken çalıştırılır; eski anahtarla şifreli
 * her kaydı yeni anahtarla yeniden şifreler. --dry-run yalnız sayar. Sonra doctor "Anahtar rotasyonu" ok ise
 * APP_PREVIOUS_KEYS kaldırılabilir.
 */
class ReencryptCommand extends Command
{
    protected $signature = 'ofisvio:reencrypt {--dry-run : Yalnız sayar}';

    protected $description = 'Eski APP_KEY ile şifreli kayıtları yeni anahtarla yeniden şifreler (anahtar rotasyonu).';

    public function handle(KeyRotationService $rotation): int
    {
        $dry = (bool) $this->option('dry-run');
        $counts = $rotation->reencrypt($dry);

        foreach ($counts as $field => $n) {
            $this->line(sprintf('%-40s %d%s', $field, $n, $dry ? ' (dry-run)' : ''));
        }

        $this->info(($dry ? 'Eski anahtarla şifreli: ' : 'Yeniden şifrelendi: ').array_sum($counts).' kayıt');

        return self::SUCCESS;
    }
}
