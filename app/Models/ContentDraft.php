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
        'content_id', 'title', 'slug', 'excerpt', 'body', 'category',
        'meta_title', 'meta_description', 'noindex', 'author_id',
    ];

    protected $casts = [
        'status' => ContentStatus::class,
        'noindex' => 'boolean',
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
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'noindex' => $this->noindex,
        ];
    }
}
