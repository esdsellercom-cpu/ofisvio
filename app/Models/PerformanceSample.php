<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/** İstek örneği (faz 60f): RequestProfiler yazar; 30 gün sonra budanır. */
class PerformanceSample extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $fillable = ['kind', 'route', 'path', 'method', 'status', 'duration_ms', 'query_count', 'query_ms', 'memory_mb', 'response_bytes', 'cache_hit', 'authenticated', 'created_at'];

    protected $casts = ['cache_hit' => 'boolean', 'authenticated' => 'boolean', 'created_at' => 'datetime', 'memory_mb' => 'float'];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(30));
    }
}
