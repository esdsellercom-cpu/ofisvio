<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Yönlendirme kaydı (faz 54): eski yol → hedef. Yalnız UrlHistoryService yazar (zincir/döngü denetimi orada).
 *
 * @property int $id
 * @property int $website_id
 * @property string $from_path
 * @property string $to_path
 * @property int $code
 * @property string $status
 * @property string $source
 * @property int|null $score
 * @property string|null $note
 * @property int $hits
 * @property Carbon|null $last_hit_at
 * @property int|null $created_by
 */
class UrlRedirect extends Model
{
    public const CODES = [301 => '301 Kalıcı', 302 => '302 Geçici', 307 => '307 Geçici (yöntem korunur)', 308 => '308 Kalıcı (yöntem korunur)'];

    public const STATUSES = ['active' => 'Etkin', 'pending' => 'Onay bekliyor', 'disabled' => 'Pasif'];

    public const SOURCES = ['manual' => 'Manuel', 'slug_change' => 'Slug değişimi', 'deleted' => 'Silinen içerik', 'auto' => 'Otomatik (benzerlik)', 'fallback' => 'Üst kategori / ana sayfa'];

    protected $fillable = ['website_id', 'from_path', 'to_path', 'code', 'status', 'source', 'score', 'note', 'hits', 'last_hit_at', 'created_by'];

    protected $casts = ['code' => 'integer', 'score' => 'integer', 'hits' => 'integer', 'last_hit_at' => 'datetime'];

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<UrlRedirect>  $query
     * @return Builder<UrlRedirect>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isPermanent(): bool
    {
        return in_array($this->code, [301, 308], true);
    }
}
