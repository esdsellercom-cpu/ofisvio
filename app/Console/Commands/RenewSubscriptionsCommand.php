<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/** Bitişi geçen auto_renew üyelikleri yeni döneme geçirir (fatura + bildirim; audit P1-5). */
class RenewSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:renew';

    protected $description = 'Otomatik yenilemeli üyelikleri yeni döneme geçirir, ayara göre fatura keser.';

    public function handle(SubscriptionService $subscriptions): int
    {
        $n = $subscriptions->renewDue();
        $this->info($n > 0 ? "{$n} üyelik yenilendi." : 'Yenilenecek üyelik yok.');

        return self::SUCCESS;
    }
}
