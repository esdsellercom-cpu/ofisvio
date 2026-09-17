<?php

namespace App\Events;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain olayı: rezervasyon durumu değişti (oluşturma dahil, from=null).
 * BookingService DB commit'inden SONRA yayınlar; dinleyiciler bildirim/analitik üretir.
 */
class BookingStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly ?BookingStatus $from,
        public readonly BookingStatus $to,
        public readonly ?string $reason = null,
    ) {}
}
