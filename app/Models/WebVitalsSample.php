<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Core Web Vitals örneği (faz 60d): PageSpeed Insights lab + alan verisi; sayfa × strateji × zaman. */
class WebVitalsSample extends Model
{
    protected $fillable = ['website_id', 'path', 'strategy', 'score', 'lab', 'field', 'measured_at'];

    protected $casts = ['lab' => 'array', 'field' => 'array', 'measured_at' => 'datetime', 'score' => 'integer'];
}
