<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Header/footer sürümü (faz 61a): her yayından önceki yapılandırma; geri alma buradan. Yalnız SiteChromeService yazar. */
class SiteChromeVersion extends Model
{
    protected $fillable = ['website_id', 'area', 'number', 'config', 'note', 'created_by'];

    protected $casts = ['config' => 'array', 'number' => 'integer'];

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
