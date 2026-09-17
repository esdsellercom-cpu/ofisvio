<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Yayınlanmış ana sayfa revizyonu (anlık görüntü). Salt okunur; geri alma yeni revizyon üretir. */
class SiteRevision extends Model
{
    protected $fillable = ['website_id', 'number', 'snapshot', 'note', 'created_by', 'published_at'];

    protected $casts = ['snapshot' => 'array', 'published_at' => 'datetime', 'number' => 'integer'];

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
