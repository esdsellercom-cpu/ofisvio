<?php

namespace App\Models;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Content extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'website_id', 'kind', 'slug', 'title', 'excerpt', 'body', 'category', 'tags', 'reading_minutes',
        'requires_approval', 'meta_title', 'meta_description', 'noindex', 'author_id',
    ];

    protected $casts = [
        'kind' => ContentKind::class,
        'status' => ContentStatus::class,
        'requires_approval' => 'boolean',
        'noindex' => 'boolean',
        'show_in_nav' => 'boolean',
        'tags' => 'array',
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
        return (string) Str::markdown((string) $this->body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
    }

    /** Sitedeki göreli yol: /blog/{slug} ya da /{slug}. */
    public function path(): string
    {
        return ($this->kind === ContentKind::POST ? '/blog/' : '/').$this->slug;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
