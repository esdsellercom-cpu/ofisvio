<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Prompt kayıt defteri (faz 60e): anahtar + sürüm; iş kaydı hangi sürümle üretildiğini taşır. Yalnız AiContentService yazar. */
class AiPrompt extends Model
{
    public const KEYS = ['draft' => 'Makale taslağı', 'refresh' => 'İçerik yenileme', 'fact_check' => 'Doğruluk kontrolü (iddia çıkarımı)'];

    protected $fillable = ['key', 'version', 'name', 'system', 'template', 'model', 'is_active', 'created_by'];

    protected $casts = ['version' => 'integer', 'is_active' => 'boolean'];
}
