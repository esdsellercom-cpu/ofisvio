<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Panel zili bildirimi (database kanalı). */
class InAppNotice extends Notification
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $event,
        public readonly ?string $entityType = null,
        public readonly ?int $entityId = null,
        public readonly ?string $url = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'event' => $this->event, 'entity_type' => $this->entityType, 'entity_id' => $this->entityId, 'url' => $this->url];
    }
}
