<?php

namespace App\Models;

use App\Content\BodyRenderer;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Content extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'website_id', 'parent_id', 'parent_slug', 'cover_media_id', 'cover_url', 'kind', 'slug', 'title', 'excerpt', 'body', 'category', 'tags', 'reading_minutes',
        'requires_approval', 'meta_title', 'meta_description', 'noindex', 'is_featured', 'author_id',
        'focus_keyword', 'related_keywords', 'canonical_url', 'robots', 'og_title', 'og_description', 'og_media_id', 'geo', 'schema_types', 'schema_custom', 'seo_score',
    ];

    /** CMS stüdyo alanları (faz 48): içerik ↔ çalışma taslağı arasında birebir taşınır. */
    public const STUDIO_FIELDS = ['focus_keyword', 'related_keywords', 'canonical_url', 'robots', 'og_title', 'og_description', 'og_media_id', 'geo', 'schema_types', 'schema_custom'];

    protected $casts = [
        'kind' => ContentKind::class,
        'status' => ContentStatus::class,
        'requires_approval' => 'boolean',
        'noindex' => 'boolean',
        'is_featured' => 'boolean',
        'show_in_nav' => 'boolean',
        'tags' => 'array',
        'related_keywords' => 'array',
        'geo' => 'array',
        'schema_types' => 'array',
        'seo_score' => 'integer',
        'reading_minutes' => 'integer',
        'scheduled_for' => 'datetime',
        'published_at' => 'datetime',
    ];

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return HasOne<ContentDraft, $this> */
    public function draft(): HasOne
    {
        return $this->hasOne(ContentDraft::class);
    }

    /** @return HasMany<ContentRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ContentRevision::class);
    }

    /**
     * Sitede görünen içerik: yayında ve yayın tarihi geçmiş.
     *
     * @param  Builder<Content>  $query
     * @return Builder<Content>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::PUBLISHED->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Markdown -> güvenli HTML. Ham HTML süzülür, güvensiz bağlantılar
     * engellenir: editör hesabı ele geçirilse bile script enjekte edilemez.
     */
    public function renderedBody(): string
    {
        // Faz 48: bloklar (:::hero …), kısa kodlar ([youtube:…], [button:…]) ve görsel öznitelikleri BodyRenderer'da;
        // markdown yine html_input=strip ile çevrilir — ham HTML hiçbir yoldan girmez.
        return app(BodyRenderer::class)->render((string) $this->body);
    }

    /** @return BelongsTo<Media, $this> */
    public function ogImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'og_media_id');
    }

    /** GEO alanı dolu mu (özet + en az bir soru/SSS)? */
    public function geoReady(): bool
    {
        $geo = (array) ($this->geo ?? []);

        return trim((string) ($geo['summary'] ?? '')) !== '' && (count((array) ($geo['faq'] ?? [])) > 0 || count((array) ($geo['questions'] ?? [])) > 0);
    }

    /** Sitedeki göreli yol: /blog/{slug} ya da /{slug}. */
    public function path(): string
    {
        if ($this->kind === ContentKind::POST) {
            return '/blog/'.$this->slug;
        }

        return ($this->parent_slug ? '/'.$this->parent_slug : '').'/'.$this->slug;
    }

    /** @return BelongsTo<Media, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /** @return BelongsTo<Content, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Content, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
