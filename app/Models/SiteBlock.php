<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vitrin bloğu (faz 10) — bkz. SiteBlockService. */
class SiteBlock extends Model
{
    protected $fillable = ['website_id', 'key', 'data', 'updated_by'];

    protected $casts = ['data' => 'array'];

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
