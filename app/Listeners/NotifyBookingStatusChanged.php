<?php

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Events\BookingStatusChanged;
use App\Services\NotificationService;

/**
 * Durum geçişi → bildirim olayı (master prompt §14). Eşleme:
 *   PENDING_APPROVAL/REQUESTED (yeni) → booking.requested
 *   CONFIRMED → booking.confirmed · REJECTED → booking.rejected
 *   CANCELLED → booking.cancelled · EXPIRED → booking.expired
 */
class NotifyBookingStatusChanged
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(BookingStatusChanged $event): void
    {
        $b = $event->booking;

        $key = match (true) {
            $event->from === null && $event->to === BookingStatus::PENDING_APPROVAL => 'booking.requested',
            $event->from === null && $event->to === BookingStatus::CONFIRMED => 'booking.confirmed',
            $event->to === BookingStatus::CONFIRMED => 'booking.confirmed',
            $event->to === BookingStatus::REJECTED => 'booking.rejected',
            $event->to === BookingStatus::CANCELLED => 'booking.cancelled',
            $event->to === BookingStatus::EXPIRED => 'booking.expired',
            default => null,
        };

        if ($key === null) {
            return;
        }

        $b->loadMissing(['room', 'location', 'company', 'booker']);

        $this->notifications->dispatch($key, [
            'reference' => (string) $b->reference,
            'customer_name' => $b->contactName(),
            'customer_phone' => (string) ($b->customer_phone ?? ''),
            'customer_email' => (string) ($b->contactEmail() ?? ''),
            'customer_user_id' => $b->booked_by,
            'company_name' => $b->customerLabel(),
            'location' => (string) $b->location?->name,
            'room' => (string) $b->room?->name,
            'date' => $b->starts_at->format('d.m.Y'),
            'time_range' => $b->starts_at->format('H:i').' – '.$b->ends_at->format('H:i'),
            'participants' => $b->participant_count,
            'status' => $event->to->label(),
            'amount' => money($b->total_amount),
            'note' => (string) ($b->note ?? ''),
            'reason' => (string) ($event->reason ?? ''),
        ], $b->location_id, 'booking', $b->id);
    }
}
