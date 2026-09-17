<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kayıtlı blok (faz 49/50): bir bölümün ayarları ad + kategori ile kütüphaneye alınır. `is_global` = bağlı bölümler
 * (site_sections.preset_id) ayarı buradan okur; blok değişince tüm kullanımlar güncellenir. Normal blok kopyalanarak eklenir.
 */
class SiteBlockPreset extends Model
{
    public const CATEGORIES = ['hero' => 'Hero', 'hizmetler' => 'Hizmetler', 'cta' => 'CTA', 'faq' => 'SSS', 'referans' => 'Referanslar', 'iletisim' => 'İletişim', 'blog' => 'Blog', 'footer' => 'Footer', 'icerik' => 'İçerik', 'ozel' => 'Özel bloklar'];

    protected $fillable = ['website_id', 'name', 'type', 'category', 'is_global', 'settings', 'created_by'];

    protected $casts = ['settings' => 'array', 'is_global' => 'boolean'];

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Bağlı taslak bölümleri.
     *
     * @return HasMany<SiteSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(SiteSection::class, 'preset_id');
    }
}
