<?php

namespace App\Console\Commands;

use App\Services\BookingService;
use Illuminate\Console\Command;

/** Onaysız kalan rezervasyon taleplerini süresi dolunca EXPIRED yapar (saat serbest kalır). */
class ExpireBookingRequestsCommand extends Command
{
    protected $signature = 'booking:expire-requests';

    protected $description = 'Onay süresi dolan PENDING_APPROVAL taleplerini EXPIRED yapar.';

    public function handle(BookingService $bookings): int
    {
        $n = $bookings->expireStale();
        $this->info($n > 0 ? "{$n} talep süresi doldu." : 'Süresi dolan talep yok.');

        return self::SUCCESS;
    }
}
