<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/** Vitrin görseli (faz 30) — bkz. MediaService. */
class Media extends Model
{
    protected $table = 'media';

    protected $fillable = [
        'website_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type',
        'size_bytes', 'width', 'height', 'checksum_sha256', 'alt',
    ];

    protected $casts = ['size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer'];

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Kapak olarak kullanan içerikler.
     *
     * @return HasMany<Content, $this>
     */
    public function contents(): HasMany
    {
        return $this->hasMany(Content::class, 'cover_media_id');
    }

    /**
     * Hero olarak kullanan siteler.
     *
     * @return HasMany<Website, $this>
     */
    public function heroOf(): HasMany
    {
        return $this->hasMany(Website::class, 'hero_media_id');
    }
}
