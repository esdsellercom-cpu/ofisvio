<?php

namespace App\Console\Commands;

use App\Services\InvoiceService;
use Illuminate\Console\Command;

/** Uzun süredir gecikmiş faturası olan aktif şirketleri askıya alır (ayar kapalıysa hiçbir şey yapmaz). */
class SuspendOverdueCompaniesCommand extends Command
{
    protected $signature = 'finance:suspend-overdue';

    protected $description = 'finance.suspend_after_overdue_days ayarına göre aktif şirketleri askıya alır.';

    public function handle(InvoiceService $invoices): int
    {
        $n = $invoices->suspendLongOverdue();
        $this->info($n > 0 ? "{$n} şirket askıya alındı." : 'Askıya alınacak şirket yok.');

        return self::SUCCESS;
    }
}
