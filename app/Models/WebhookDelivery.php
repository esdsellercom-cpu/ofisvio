<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Giden webhook teslimatı (faz 61c): bir olayın bir uca gönderimi; denemeler aynı satırda sayılır.
 * `payload` maskeli kopyadır (e-posta/telefon/ad yok; ekran için); `body` gönderilen gövdenin şifreli kopyasıdır —
 * panelde gösterilmez, yalnız tekrar gönderim/yeniden deneme aynı gövdeyi okur.
 */
class WebhookDelivery extends Model
{
    public const STATUSES = ['pending' => 'Bekliyor', 'success' => 'Başarılı', 'failed' => 'Başarısız'];

    protected $fillable = ['endpoint_id', 'event', 'delivery_id', 'payload', 'body', 'status', 'attempts', 'response_status', 'duration_ms', 'error', 'next_retry_at', 'delivered_at', 'manual'];

    protected $hidden = ['body'];

    protected $casts = ['payload' => 'array', 'body' => 'encrypted', 'attempts' => 'int', 'response_status' => 'int', 'duration_ms' => 'int', 'next_retry_at' => 'datetime', 'delivered_at' => 'datetime', 'manual' => 'bool'];

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }
}
