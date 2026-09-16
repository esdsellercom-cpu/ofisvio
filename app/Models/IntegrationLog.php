<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Giden entegrasyon isteği kaydı (faz 5) — secret ve gövde taşımaz. */
class IntegrationLog extends Model
{
    protected $fillable = ['provider', 'method', 'path', 'status', 'duration_ms', 'ok', 'error'];

    protected $casts = ['ok' => 'boolean', 'status' => 'integer', 'duration_ms' => 'integer'];
}
