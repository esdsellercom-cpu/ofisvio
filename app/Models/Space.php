<?php

namespace App\Models;

use App\Models\Concerns\HasMaintenanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Masa / ofis (audit P0-2). Saatlik odalar Room'da; burası aylık tahsis edilen envanter.
 *
 * @property-read int|null $active_assignments_count  withCount ile yüklenir
 * @property Carbon|null $maintenance_until
 * @property string|null $maintenance_note
 * @property int|null $cover_media_id
 * @property array<int, string>|null $amenities
 */
class Space extends Model
{
    use HasMaintenanceStatus;

    public const KINDS = ['desk_fixed' => 'Sabit masa', 'desk_flex' => 'Esnek masa', 'office' => 'Özel ofis'];

    protected $fillable = ['location_id', 'kind', 'name', 'floor', 'zone', 'capacity', 'monthly_price', 'is_active', 'sort_order', 'notes', 'amenities', 'cover_media_id', 'maintenance_until', 'maintenance_note'];

    protected $casts = ['capacity' => 'integer', 'monthly_price' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer', 'amenities' => 'array', 'maintenance_until' => 'date'];

    /** @return BelongsTo<Media, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /** @return array<int, string> */
    public function amenityList(): array
    {
        return array_values(array_filter(array_map('strval', (array) ($this->amenities ?? []))));
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<SpaceAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(SpaceAssignment::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** Sabit masa ve ofis tek şirkete; esnek masa alanı kapasite kadar eşzamanlı tahsis alır. */
    public function slots(): int
    {
        return $this->kind === 'desk_flex' ? max(1, $this->capacity) : 1;
    }

    public function occupied(): int
    {
        return $this->active_assignments_count ?? $this->assignments()->where('status', 'active')->count();
    }

    public function isFull(): bool
    {
        return $this->occupied() >= $this->slots();
    }
}
