<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 404 günlüğü (faz 54): yol başına tek satır, isabet sayacı ve öneri. Yalnız RedirectService yazar.
 *
 * @property int $id
 * @property int $website_id
 * @property string $path
 * @property int $hits
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property string|null $referer
 * @property string|null $suggested_path
 * @property int|null $suggested_score
 * @property string $status
 */
class NotFoundLog extends Model
{
    public const STATUSES = ['open' => 'Açık', 'redirected' => 'Yönlendirildi', 'ignored' => 'Yok sayıldı'];

    protected $fillable = ['website_id', 'path', 'hits', 'first_seen_at', 'last_seen_at', 'referer', 'suggested_path', 'suggested_score', 'status'];

    protected $casts = ['hits' => 'integer', 'suggested_score' => 'integer', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
}
