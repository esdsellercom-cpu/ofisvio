<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Etkinlik (faz 39d): panelden açılır, yayınlanınca vitrinde. Ticari içerik yalnız DB.
 *
 * @property-read int|null $registrations_active_count  withCount ile yüklenir (iptal hariç)
 */
class Event extends Model
{
    protected $fillable = [
        'title', 'slug', 'summary', 'description', 'location_id', 'room_id', 'starts_at', 'ends_at', 'capacity', 'price',
        'is_published', 'registration_open', 'cover_media_id', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime', 'ends_at' => 'datetime', 'capacity' => 'integer', 'price' => 'integer',
        'is_published' => 'boolean', 'registration_open' => 'boolean',
    ];

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /** @return HasMany<EventRegistration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function path(): string
    {
        return '/etkinlik/'.$this->slug;
    }

    public function renderedDescription(): string
    {
        return (string) Str::markdown((string) $this->description, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function isPast(): bool
    {
        return $this->ends_at->isPast();
    }

    /** Aktif (iptal edilmemiş) kayıt sayısı; capacity ile karşılaştırılır. */
    public function activeRegistrations(): int
    {
        return $this->registrations_active_count ?? $this->registrations()->where('status', '!=', 'cancelled')->count();
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->activeRegistrations() >= $this->capacity;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
