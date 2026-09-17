<?php

namespace App\Notifications\Channels;

use App\Models\NotificationLog;

/** Bildirim kanalı adaptörü: günlük kaydını sağlayıcıya iletir. */
interface ChannelInterface
{
    public function providerName(): string;

    public function isAvailable(): bool;

    public function unavailableReason(): string;

    /**
     * Gönderir; sağlayıcı mesaj kimliği döndürür (yoksa boş).
     *
     * @throws \Throwable yeniden denenecek hata
     */
    public function send(NotificationLog $log): string;
}
