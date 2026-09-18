<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Command Center bulgu kararı (faz 60): yalnız durum/not; bulgunun kendisi her açılışta yeniden hesaplanır.
 * Yalnız App\Seo\HealthCenter yazar.
 */
class SeoIssue extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_IGNORED, self::STATUS_RESOLVED];

    protected $fillable = ['website_id', 'issue_key', 'category', 'status', 'note', 'decided_by', 'decided_at', 'last_seen_at'];

    protected $casts = ['decided_at' => 'datetime', 'last_seen_at' => 'datetime'];

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
