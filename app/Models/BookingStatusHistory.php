<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rezervasyon durum geçmişi — yalnız BookingService::transition yazar. */
class BookingStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'booking_status_history';

    protected $fillable = ['booking_id', 'from_status', 'to_status', 'actor_id', 'reason', 'created_at'];

    protected $casts = ['from_status' => BookingStatus::class, 'to_status' => BookingStatus::class, 'created_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
