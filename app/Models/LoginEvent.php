<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Giriş geçmişi (audit): Auth/Fortify olaylarından; 180 gün sonra `model:prune` ile budanır. */
class LoginEvent extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    public const EVENTS = ['login' => 'Giriş', 'failed' => 'Başarısız deneme', 'logout' => 'Çıkış', 'lockout' => 'Kilitlendi (çok deneme)', 'password_reset' => 'Şifre sıfırlandı', 'two_factor' => '2FA doğrulandı', 'other_devices_logout' => 'Diğer cihazlar kapatıldı'];

    protected $fillable = ['user_id', 'email', 'event', 'ip', 'user_agent', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::EVENTS[$this->event] ?? $this->event;
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(180));
    }
}
