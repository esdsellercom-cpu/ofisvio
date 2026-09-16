<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Location extends Model
{
    protected $fillable = [
        'name', 'slug', 'city', 'region', 'address_line', 'badge',
        'tags', 'price_from', 'is_active', 'is_published', 'sort_order',
        'latitude', 'longitude', 'district', 'postal_code', 'phone', 'opening_hours',
        'geo_description', 'geo_meta_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'tags' => 'array',
        'opening_hours' => 'array',
        'sort_order' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** Sitedeki göreli yol. */
    public function path(): string
    {
        return '/lokasyon/'.$this->slug;
    }

    /** Markdown -> güvenli HTML (Content::renderedBody ile aynı politika). */
    public function renderedDescription(): string
    {
        return (string) Str::markdown((string) $this->geo_description, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

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
