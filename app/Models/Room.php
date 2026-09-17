<?php

namespace App\Models;

use App\Models\Concerns\HasMaintenanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rezervasyona açık oda (toplantı/etkinlik/odaklanma). Lokasyona bağlı Ofisvio
 * varlığıdır, tenant scope taşımaz; yönetimi geo.edit (lokasyon künyesi gibi).
 */
class Room extends Model
{
    use HasMaintenanceStatus;

    public const KINDS = ['meeting' => 'Toplantı odası', 'event' => 'Etkinlik alanı', 'focus' => 'Odaklanma odası'];

    protected $fillable = [
        'location_id', 'name', 'kind', 'capacity', 'hourly_rate', 'open_from', 'open_until',
        'slot_minutes', 'max_hours', 'is_active', 'sort_order', 'description',
        'amenities', 'cover_media_id', 'maintenance_until', 'maintenance_note',
    ];

    protected $casts = [
        'capacity' => 'integer', 'hourly_rate' => 'integer', 'slot_minutes' => 'integer',
        'max_hours' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer',
        'amenities' => 'array', 'maintenance_until' => 'date',
    ];

    /**
     * Kapak görseli: lokasyon galerisinden seçilir (LocationMedia), medya kütüphanesindeki kayıt.
     *
     * @return BelongsTo<Media, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /** @return array<int, string> */
    public function amenityList(): array
    {
        return array_values(array_filter(array_map('strval', (array) ($this->amenities ?? []))));
    }

    /** Rezervasyona açık mı: aktif + bakımda değil (lokasyon ayrıca denetlenir). */
    public function isBookable(): bool
    {
        return $this->is_active && ! $this->isUnderMaintenance();
    }

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
