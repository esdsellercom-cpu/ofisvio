<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Giden webhook ucu (faz 61c). `secret` şifreli saklanır (encrypted cast); panelde asla gösterilmez, yalnız
 * imza üretiminde (DeliverWebhook) okunur. `events` = WebhookEvents anahtarları.
 */
class WebhookEndpoint extends Model
{
    protected $fillable = ['name', 'url', 'secret', 'events', 'is_active', 'retry_max', 'timeout_seconds', 'description', 'created_by'];

    protected $hidden = ['secret'];

    protected $casts = ['secret' => 'encrypted', 'events' => 'array', 'is_active' => 'bool', 'retry_max' => 'int', 'timeout_seconds' => 'int', 'last_success_at' => 'datetime', 'last_failure_at' => 'datetime'];

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public function subscribed(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }

    public function host(): string
    {
        return (string) parse_url($this->url, PHP_URL_HOST);
    }
}
