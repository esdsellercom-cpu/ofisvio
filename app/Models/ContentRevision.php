<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['content_id', 'number', 'title', 'excerpt', 'body', 'edited_by', 'created_at'];

    protected $casts = ['created_at' => 'datetime', 'number' => 'integer'];

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
