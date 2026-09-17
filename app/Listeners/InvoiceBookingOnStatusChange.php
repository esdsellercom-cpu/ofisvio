<?php

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Events\BookingStatusChanged;
use App\Services\InvoiceService;

/**
 * Rezervasyon → fatura (audit P1-12 / H-6). Onaylanınca (şirket hesabı + tutar) fatura yayınlanır;
 * iptal/red/süre dolumunda ödemesi olmayan açık fatura sistemce iptal edilir. Olay tabanlı: BookingService
 * finans servisini tanımaz; ayar finance.auto_invoice_bookings kapalıysa hiçbir şey olmaz.
 * Otomatik keşifle kaydolur (EventServiceProvider'a elle eklenmez — çift dinleyici).
 */
class InvoiceBookingOnStatusChange
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function handle(BookingStatusChanged $event): void
    {
        if ($event->to === BookingStatus::CONFIRMED) {
            $this->invoices->createForBooking($event->booking);

            return;
        }

        if (in_array($event->to, [BookingStatus::CANCELLED, BookingStatus::REJECTED, BookingStatus::EXPIRED], true)) {
            $this->invoices->cancelForBooking($event->booking, 'Rezervasyon '.$event->to->label().' (otomatik)');
        }
    }
}
