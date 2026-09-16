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

    protected $fillable = [
        'organization_id', 'name', 'slug', 'domain', 'theme', 'is_default',
        'seo_title_suffix', 'seo_default_description', 'robots_index', 'seo_locale',
        'same_as', 'legal_name', 'nav_links',
        'cache_ttl_seconds', 'http_max_age', 'http_s_maxage',
    ];

    protected $casts = ['is_default' => 'boolean', 'robots_index' => 'boolean', 'same_as' => 'array', 'nav_links' => 'array'];

    /** Sitenin mutlak kök adresi: alan adı varsa https ile, yoksa uygulama adresi. */
    public function baseUrl(): string
    {
        return $this->domain ? 'https://'.$this->domain : rtrim((string) config('app.url'), '/');
    }

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
