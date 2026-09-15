<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $fillable = [
        'name', 'slug', 'city', 'region', 'address_line', 'badge',
        'tags', 'price_from', 'is_active', 'is_published', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'tags' => 'array',
        'sort_order' => 'integer',
    ];

    /**
     * Sitede görünecek lokasyonlar.
     *
     * is_published ve is_active AYRI koşullardır: bir şube operasyonda aktif
     * olup henüz siteye açılmamış olabilir (açılış öncesi kurulum).
     *
     * @param  Builder<Location>  $query
     * @return Builder<Location>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
