<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Etkinlik kaydı (vitrin formu; KVKK rızası + IP). */
class EventRegistration extends Model
{
    public const STATUSES = ['registered' => 'Kayıtlı', 'attended' => 'Katıldı', 'cancelled' => 'İptal'];

    protected $fillable = ['event_id', 'name', 'email', 'phone', 'company_name', 'note', 'status', 'consented_at', 'consent_ip'];

    protected $casts = ['consented_at' => 'datetime'];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
