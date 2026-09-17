<?php

namespace App\Console\Commands;

use App\Services\InvoiceService;
use Illuminate\Console\Command;

/** Vade + tolerans geçen yayınlanmış faturaları GECİKMİŞ yapar (audit'li). */
class MarkOverdueInvoicesCommand extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Vadesi geçmiş yayınlanmış faturaları gecikmiş olarak işaretler.';

    public function handle(InvoiceService $invoices): int
    {
        $n = $invoices->markOverdue();
        $this->info($n > 0 ? "{$n} fatura gecikmiş olarak işaretlendi." : 'Gecikmiş fatura yok.');

        return self::SUCCESS;
    }
}
