<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Hizmet (faz 4): operatörün sattığı çözüm (sanal ofis, hazır ofis, coworking, toplantı odası…).
 * Lokasyonlarla ilişkisel (location_service). Tenant scope taşımaz — operatör varlığı.
 * booking_kind dolu hizmetler rezervasyon akışına bağlanır (odalar o türde).
 */
class Service extends Model
{
    public const BOOKING_KINDS = ['meeting' => 'Toplantı odası', 'event' => 'Etkinlik alanı', 'focus' => 'Odaklanma odası'];

    protected $fillable = ['name', 'slug', 'summary', 'description', 'price_text', 'booking_kind', 'is_flagship', 'is_active', 'sort_order', 'cover_media_id', 'updated_by'];

    protected $casts = ['is_flagship' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    /**
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    /** @return BelongsToMany<Location, $this> */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_service')->withPivot('sort_order')->withTimestamps();
    }

    /** @return BelongsTo<Media, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function path(): string
    {
        return '/cozum/'.$this->slug; // /hizmet* CMS sayfa slug'larıyla çakışmasın diye
    }

    public function renderedDescription(): string
    {
        return (string) Str::markdown((string) $this->description, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
