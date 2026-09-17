<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/** Bitiş tarihi geçen aktif üyelikleri EXPIRED yapar (audit'li). */
class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Bitiş tarihi geçmiş aktif üyelikleri süresi dolmuş olarak işaretler.';

    public function handle(SubscriptionService $subscriptions): int
    {
        $n = $subscriptions->expireStale();
        $this->info($n > 0 ? "{$n} üyeliğin süresi doldu." : 'Süresi dolan üyelik yok.');

        return self::SUCCESS;
    }
}
