<?php

namespace App\Notifications\Channels;

use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\InAppNotice;
use App\Services\BookingService;
use RuntimeException;

/** Uygulama içi: Laravel database notification (panel zili). Alıcı 'user:<id>'. */
class InAppChannel implements ChannelInterface
{
    public function __construct(private readonly BookingService $bookings) {}

    public function providerName(): string
    {
        return 'database';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): string
    {
        return '';
    }

    public function send(NotificationLog $log): string
    {
        $user = User::query()->find((int) substr($log->recipient, 5));

        if ($user === null) {
            throw new RuntimeException('user_missing kullanıcı bulunamadı: '.$log->recipient);
        }

        $title = $log->subject ?: mb_substr(strtok($log->body, "\n") ?: 'Bildirim', 0, 160);
        $url = null;

        if ($log->entity_type === 'booking' && $log->entity_id !== null && ($booking = $this->bookings->findAny((int) $log->entity_id)) !== null) {
            $url = route('panel.bookings.show', [$booking->location, $booking->id]);
        }

        $user->notify(new InAppNotice($title, $log->body, $log->event, $log->entity_type, $log->entity_id, $url));

        return '';
    }
}
