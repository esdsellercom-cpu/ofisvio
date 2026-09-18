<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/** Yavaş sorgu kaydı (faz 60f): bağlamsız SQL; RequestProfiler yazar; 30 gün sonra budanır. */
class SlowQuery extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $fillable = ['route', 'sql_hash', 'sql', 'duration_ms', 'connection', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(30));
    }
}
