<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kayıtlı blok (faz 49): bir bölümün ayarları ad verilerek kütüphaneye alınır, başka yerde yeniden kullanılır. */
class SiteBlockPreset extends Model
{
    protected $fillable = ['website_id', 'name', 'type', 'settings', 'created_by'];

    protected $casts = ['settings' => 'array'];

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
