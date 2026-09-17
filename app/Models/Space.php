<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Masa / ofis (audit P0-2). Saatlik odalar Room'da; burası aylık tahsis edilen envanter.
 *
 * @property-read int|null $active_assignments_count  withCount ile yüklenir
 */
class Space extends Model
{
    public const KINDS = ['desk_fixed' => 'Sabit masa', 'desk_flex' => 'Esnek masa', 'office' => 'Özel ofis'];

    protected $fillable = ['location_id', 'kind', 'name', 'floor', 'zone', 'capacity', 'monthly_price', 'is_active', 'sort_order', 'notes'];

    protected $casts = ['capacity' => 'integer', 'monthly_price' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];

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
