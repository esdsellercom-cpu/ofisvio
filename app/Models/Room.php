<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rezervasyona açık oda (toplantı/etkinlik/odaklanma). Lokasyona bağlı Ofisvio
 * varlığıdır, tenant scope taşımaz; yönetimi geo.edit (lokasyon künyesi gibi).
 */
class Room extends Model
{
    public const KINDS = ['meeting' => 'Toplantı odası', 'event' => 'Etkinlik alanı', 'focus' => 'Odaklanma odası'];

    protected $fillable = [
        'location_id', 'name', 'kind', 'capacity', 'hourly_rate', 'open_from', 'open_until',
        'slot_minutes', 'max_hours', 'is_active', 'sort_order', 'description',
    ];

    protected $casts = [
        'capacity' => 'integer', 'hourly_rate' => 'integer', 'slot_minutes' => 'integer',
        'max_hours' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer',
    ];

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
