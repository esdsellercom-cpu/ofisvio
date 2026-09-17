<?php

namespace App\Console\Commands;

use App\Services\InvoiceService;
use Illuminate\Console\Command;

/** Vadeye N gün kala tek seferlik fatura hatırlatması (invoice.due_soon; audit P1-7). */
class RemindDueInvoicesCommand extends Command
{
    protected $signature = 'invoices:remind-due';

    protected $description = 'Vadesi yaklaşan faturalar için müşteriye hatırlatma gönderir.';

    public function handle(InvoiceService $invoices): int
    {
        $n = $invoices->remindDueSoon();
        $this->info($n > 0 ? "{$n} fatura için hatırlatma kuyruğa alındı." : 'Hatırlatılacak fatura yok.');

        return self::SUCCESS;
    }
}
