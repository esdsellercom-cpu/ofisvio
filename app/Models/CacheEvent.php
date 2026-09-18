<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/** Önbellek olayı (faz 60f): geçersizleme kaskadının izi (tetikleyici, varlık, adımlar, yeni sürüm). */
class CacheEvent extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $fillable = ['website_id', 'trigger', 'entity_type', 'entity_id', 'steps', 'version_after', 'created_at'];

    protected $casts = ['steps' => 'array', 'created_at' => 'datetime'];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(30));
    }
}
