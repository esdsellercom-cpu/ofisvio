<?php

namespace App\Models;

use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Yayındaki içeriğin çalışma taslağı (bkz. migration). Alan kümesi Content ile
 * aynıdır; yayınlanınca ContentService::publishDraft() içeriğe birleştirir.
 */
class ContentDraft extends Model
{
    protected $fillable = [
        'content_id', 'title', 'slug', 'excerpt', 'body', 'category', 'tags',
        'meta_title', 'meta_description', 'noindex', 'author_id',
        'focus_keyword', 'related_keywords', 'canonical_url', 'robots', 'og_title', 'og_description', 'og_media_id', 'geo', 'schema_types', 'schema_custom',
    ];

    protected $casts = [
        'status' => ContentStatus::class,
        'noindex' => 'boolean',
        'tags' => 'array',
        'related_keywords' => 'array',
        'geo' => 'array',
        'schema_types' => 'array',
        'scheduled_for' => 'datetime',
    ];

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** İçeriğe aktarılacak alanlar. @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'body' => $this->body,
            'category' => $this->category,
            'tags' => $this->tags,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'noindex' => $this->noindex,
            'focus_keyword' => $this->focus_keyword,
            'related_keywords' => $this->related_keywords,
            'canonical_url' => $this->canonical_url,
            'robots' => $this->robots,
            'og_title' => $this->og_title,
            'og_description' => $this->og_description,
            'og_media_id' => $this->og_media_id,
            'geo' => $this->geo,
            'schema_types' => $this->schema_types,
            'schema_custom' => $this->schema_custom,
        ];
    }
}
