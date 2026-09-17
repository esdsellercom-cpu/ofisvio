<?php

namespace App\Models;

use App\Models\Concerns\HasMaintenanceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Location extends Model
{
    use HasMaintenanceStatus;

    protected $fillable = [
        'name', 'slug', 'city', 'region', 'address_line', 'badge',
        'price_from', 'is_active', 'is_published', 'sort_order',
        'latitude', 'longitude', 'district', 'postal_code', 'phone', 'opening_hours',
        'geo_description', 'geo_meta_description', 'cover_media_id',
        'maintenance_until', 'maintenance_note',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'opening_hours' => 'array',
        'sort_order' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'maintenance_until' => 'date',
    ];

    /**
     * Kapak görseli (denormalize; kartlar için).
     *
     * @return BelongsTo<Media, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /**
     * Görsel bağları (kategori/sıra).
     *
     * @return HasMany<LocationMedia, $this>
     */
    public function mediaLinks(): HasMany
    {
        return $this->hasMany(LocationMedia::class)->orderBy('category')->orderBy('sort_order');
    }

    /**
     * Sunulan hizmetler (faz 4) — ilişkisel; etiket dizisi yok.
     *
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'location_service')->withPivot('sort_order')->withTimestamps()->orderBy('services.sort_order')->orderBy('services.name');
    }

    /** Aktif hizmet adları (kart etiketleri, süzgeç). @return array<int, string> */
    public function serviceNames(): array
    {
        return $this->services->where('is_active', true)->pluck('name')->values()->all();
    }

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
