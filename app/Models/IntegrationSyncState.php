<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Sağlayıcı senkron durumu (faz 60d): son başarı/deneme/hata + meta (mülkler, sitemap durumu). */
class IntegrationSyncState extends Model
{
    protected $fillable = ['website_id', 'provider', 'last_success_at', 'last_attempt_at', 'last_error', 'meta'];

    protected $casts = ['last_success_at' => 'datetime', 'last_attempt_at' => 'datetime', 'meta' => 'array'];
}
