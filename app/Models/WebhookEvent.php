<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** İmzası doğrulanmış gelen webhook olayı (faz 5). */
class WebhookEvent extends Model
{
    protected $fillable = ['provider', 'event_id', 'payload', 'status', 'source_ip', 'received_at', 'processed_at', 'error'];

    protected $casts = ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
}
