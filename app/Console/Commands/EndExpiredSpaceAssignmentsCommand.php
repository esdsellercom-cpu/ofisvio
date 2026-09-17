<?php

namespace App\Console\Commands;

use App\Services\SpaceService;
use Illuminate\Console\Command;

/** Bitiş tarihi geçmiş aktif alan tahsislerini sonlandırır (audit'li). */
class EndExpiredSpaceAssignmentsCommand extends Command
{
    protected $signature = 'spaces:end-expired';

    protected $description = 'Bitiş tarihi geçmiş masa/ofis tahsislerini sona erdirir.';

    public function handle(SpaceService $spaces): int
    {
        $n = $spaces->endExpired();
        $this->info($n > 0 ? "{$n} tahsis sona erdi." : 'Süresi dolan tahsis yok.');

        return self::SUCCESS;
    }
}
