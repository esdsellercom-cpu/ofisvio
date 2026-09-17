<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gönderim günlüğü — her deneme burada; secret/token asla yazılmaz. */
class NotificationLog extends Model
{
    public const STATUSES = ['queued' => 'Kuyrukta', 'sent' => 'Gönderildi', 'delivered' => 'Teslim edildi', 'failed' => 'Başarısız', 'skipped' => 'Atlandı'];

    protected $fillable = [
        'event', 'channel', 'recipient', 'recipient_id', 'provider', 'template', 'subject', 'body', 'status',
        'provider_message_id', 'attempt', 'entity_type', 'entity_id', 'queued_at', 'sent_at', 'delivered_at', 'failed_at', 'error_code', 'error',
    ];

    protected $casts = ['queued_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'failed_at' => 'datetime', 'attempt' => 'integer'];

    /** @return BelongsTo<NotificationRecipient, $this> */
    public function recipientRecord(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'recipient_id');
    }

    public function maskedRecipient(): string
    {
        $a = $this->recipient;

        if (str_contains($a, '@')) {
            [$u, $d] = explode('@', $a, 2);

            return mb_substr($u, 0, 2).'***@'.$d;
        }

        if (str_starts_with($a, 'user:')) {
            return $a;
        }

        return strlen($a) > 6 ? substr($a, 0, 4).str_repeat('*', strlen($a) - 6).substr($a, -2) : '***';
    }
}
