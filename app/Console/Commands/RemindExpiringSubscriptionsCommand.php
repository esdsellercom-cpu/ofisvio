<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/** Bitişe N gün kala tek seferlik üyelik bildirimi (subscription.expiring). */
class RemindExpiringSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:remind-expiring';

    protected $description = 'Bitişi yaklaşan üyelikler için müşteriye bildirim gönderir.';

    public function handle(SubscriptionService $subscriptions): int
    {
        $n = $subscriptions->remindExpiring();
        $this->info($n > 0 ? "{$n} üyelik için bildirim kuyruğa alındı." : 'Bildirilecek üyelik yok.');

        return self::SUCCESS;
    }
}
