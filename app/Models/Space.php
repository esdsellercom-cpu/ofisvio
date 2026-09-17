<?php

namespace App\Models;

use App\Models\Concerns\HasMaintenanceStatus;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Masa / ofis (audit P0-2). Saatlik odalar Room'da; burası aylık tahsis edilen envanter.
 *
 * @property-read int|null $active_assignments_count  withCount ile yüklenir
 * @property-read int|null $assets_count  withCount ile yüklenir
 * @property string|null $code
 * @property Carbon|null $maintenance_until
 * @property string|null $maintenance_note
 * @property int|null $cover_media_id
 * @property array<int, string>|null $amenities
 */
class Space extends Model
{
    use HasMaintenanceStatus;

    public const KINDS = ['desk_fixed' => 'Sabit masa', 'desk_flex' => 'Esnek masa', 'office' => 'Özel ofis', 'workspace' => 'Sabit çalışma alanı', 'other' => 'Diğer'];

    /** Sekme kümeleri (faz 46): masalar / ofisler. */
    public const DESK_KINDS = ['desk_fixed', 'desk_flex', 'workspace'];

    protected $fillable = ['location_id', 'kind', 'name', 'code', 'floor', 'zone', 'capacity', 'monthly_price', 'is_active', 'sort_order', 'notes', 'amenities', 'cover_media_id', 'maintenance_until', 'maintenance_note'];

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

    /**
     * Aktif tahsisler (envanter kartı: kime tahsisli).
     *
     * @return HasMany<SpaceAssignment, $this>
     */
    public function activeAssignments(): HasMany
    {
        return $this->hasMany(SpaceAssignment::class)->withoutGlobalScope(TenantScope::class)->where('status', 'active')->with(['company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class), 'user'])->orderBy('starts_on');
    }

    /**
     * Alana yerleşik demirbaşlar.
     *
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /** Kart durumu: inactive | maintenance | assigned | available */
    public function inventoryStatus(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->isUnderMaintenance()) {
            return 'maintenance';
        }

        return $this->isFull() ? 'assigned' : 'available';
    }

    public function inventoryLabel(): string
    {
        return match ($this->inventoryStatus()) {
            'inactive' => 'Pasif', 'maintenance' => 'Bakımda', 'assigned' => 'Tahsisli', default => 'Müsait',
        };
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
