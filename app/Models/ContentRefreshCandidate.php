<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** İçerik yenileme adayı (faz 60e): tespit gerekçeleri + puan + karar. Yalnız ContentRefreshService yazar. */
class ContentRefreshCandidate extends Model
{
    public const STATUSES = ['open' => 'Açık', 'planned' => 'Planlandı', 'done' => 'Tamamlandı', 'ignored' => 'Yok sayıldı'];

    protected $fillable = ['website_id', 'content_id', 'reasons', 'score', 'status', 'ai_job_id', 'decided_by', 'detected_at'];

    protected $casts = ['reasons' => 'array', 'score' => 'integer', 'detected_at' => 'datetime'];

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
