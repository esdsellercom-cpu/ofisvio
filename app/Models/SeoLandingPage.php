<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Programatik SEO sayfası (faz 60b): hizmet × şehir (/{hizmet-slug}/{sehir-slug}). Tek tek oluşturulur — toplu
 * üretim ucu yok; yayın yalnız kalite denetimi (benzersiz içerik uzunluğu, kopya benzerliği, başlık tekilliği)
 * geçince. Yalnız LandingPageService yazar.
 */
class SeoLandingPage extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = ['website_id', 'service_id', 'location_id', 'city_slug', 'title', 'meta_description', 'intro', 'body', 'faq', 'is_indexable', 'status', 'published_at', 'quality', 'created_by', 'updated_by'];

    protected $casts = ['faq' => 'array', 'quality' => 'array', 'is_indexable' => 'boolean', 'published_at' => 'datetime'];

    /**
     * @param  Builder<SeoLandingPage>  $query
     * @return Builder<SeoLandingPage>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)->whereNotNull('published_at');
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function path(): string
    {
        return '/'.$this->service->slug.'/'.$this->city_slug;
    }

    /** @return array<int, array{q: string, a: string}> */
    public function faqPairs(): array
    {
        $rows = [];

        foreach ((array) ($this->faq ?? []) as $row) {
            if (is_array($row) && trim((string) ($row['q'] ?? '')) !== '' && trim((string) ($row['a'] ?? '')) !== '') {
                $rows[] = ['q' => trim((string) $row['q']), 'a' => trim((string) $row['a'])];
            }
        }

        return $rows;
    }
}
