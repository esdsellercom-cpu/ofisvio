<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lokasyon ↔ görsel bağı: kategori, sıra, birincil. Yalnız LocationMediaService yazar. */
class LocationMedia extends Model
{
    public const CATEGORIES = [
        'cover' => 'Kapak',
        'gallery' => 'Galeri',
        'interior' => 'İç mekân',
        'exterior' => 'Dış mekân',
        'meeting_room' => 'Toplantı odası',
        'office' => 'Ofis',
        'coworking' => 'Coworking',
        'reception' => 'Resepsiyon',
        'common_area' => 'Ortak alan',
        'amenity' => 'Olanak',
    ];

    protected $table = 'location_media';

    protected $fillable = ['location_id', 'media_id', 'category', 'sort_order', 'is_primary', 'updated_by'];

    protected $casts = ['sort_order' => 'integer', 'is_primary' => 'boolean'];

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
