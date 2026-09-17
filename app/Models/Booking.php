<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Oda rezervasyonu — müşteri şirketine aittir (company_id tenant sınırı).
 * Durum yalnızca BookingService yazar: confirmed (oluşturuldu) | cancelled.
 */
class Booking extends Model
{
    use BelongsToTenant;

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_CONFIRMED => 'Onaylı', self::STATUS_CANCELLED => 'İptal'];

    protected $fillable = [
        'company_id', 'room_id', 'location_id', 'booked_by', 'starts_at', 'ends_at', 'status',
        'total_amount', 'note', 'overridden', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $casts = [
        'starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime',
        'total_amount' => 'integer', 'overridden' => 'boolean',
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function booker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isUpcoming(): bool
    {
        return $this->isActive() && $this->ends_at->greaterThan(Carbon::now());
    }

    public function hours(): float
    {
        return round($this->starts_at->diffInMinutes($this->ends_at) / 60, 2);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
