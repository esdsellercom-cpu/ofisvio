<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI içerik işi (faz 60e): Konu → Brief → AI Draft → Fact Check → SEO → GEO → Duplicate → Review → Approval →
 * Schedule → Publish. Her aşama insan tetiklemesiyle ilerler; sağlayıcı/model/prompt sürümü/token/maliyet kayıtlı.
 */
class AiJob extends Model
{
    public const STAGES = ['brief' => 'Brief', 'draft' => 'AI taslak', 'fact_check' => 'Doğruluk kontrolü', 'seo' => 'SEO', 'geo' => 'GEO', 'duplicate' => 'Kopya denetimi', 'review' => 'İnceleme', 'approval' => 'Onay', 'schedule' => 'Zamanlama', 'publish' => 'Yayın', 'done' => 'Tamamlandı', 'rejected' => 'Reddedildi'];

    public const ORDER = ['brief', 'draft', 'fact_check', 'seo', 'geo', 'duplicate', 'review', 'approval', 'schedule', 'publish', 'done'];

    protected $fillable = ['website_id', 'kind', 'stage', 'topic', 'brief', 'draft', 'checks', 'history', 'provider', 'model', 'prompt_key', 'prompt_version', 'input_tokens', 'output_tokens', 'cost', 'cost_currency', 'source_content_id', 'content_id', 'scheduled_for', 'created_by', 'reviewed_by', 'approved_by', 'error'];

    protected $casts = ['brief' => 'array', 'draft' => 'array', 'checks' => 'array', 'history' => 'array', 'scheduled_for' => 'datetime', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'cost' => 'decimal:4'];

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /** @return BelongsTo<Content, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'source_content_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stageIndex(): int
    {
        return (int) array_search($this->stage, self::ORDER, true);
    }
}
