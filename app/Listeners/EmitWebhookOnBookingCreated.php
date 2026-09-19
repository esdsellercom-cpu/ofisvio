<?php

namespace App\Listeners;

use App\Events\BookingStatusChanged;
use App\Webhooks\WebhookDispatcher;

/**
 * Giden webhook (faz 61c): rezervasyon ilk kez oluştuğunda (`from` yok) `booking.created`. Otomatik keşifle kaydolur.
 */
class EmitWebhookOnBookingCreated
{
    public function __construct(private readonly WebhookDispatcher $webhooks) {}

    public function handle(BookingStatusChanged $event): void
    {
        if ($event->from !== null) {
            return;
        }

        $b = $event->booking;
        $this->webhooks->emit('booking.created', [
            'booking_id' => $b->id,
            'reference' => $b->reference,
            'status' => $b->status->value,
            'company_id' => $b->company_id,
            'location_id' => $b->location_id,
            'room_id' => $b->room_id,
            'starts_at' => $b->starts_at->toIso8601String(),
            'ends_at' => $b->ends_at->toIso8601String(),
            'participant_count' => $b->participant_count,
            'total_amount' => $b->total_amount,
            'source' => $b->source,
            'customer_name' => $b->customer_name,
            'customer_email' => $b->customer_email,
            'customer_phone' => $b->customer_phone,
        ]);
    }
}
