<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Website — §66. organization_id NULL: Ofisvio'nun kendi vitrini.
 *
 * TenantScope TAŞIMAZ (bilinçli): vitrin herkese açıktır ve personel içerik
 * yönetimi tenant context'i olmadan çalışır. Müşteri siteleri (faz 10)
 * gelince erişim ContentService'te organization_id ile süzülür; o fazda
 * BelongsToTenant eklenip varsayılan site için istisna tanımlanacak.
 */
class Website extends Model
{
    use SoftDeletes;

    protected $fillable = ['organization_id', 'name', 'slug', 'domain', 'is_default'];

    protected $casts = ['is_default' => 'boolean'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Content, $this> */
    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    /**
     * @param  Builder<Website>  $query
     * @return Builder<Website>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
