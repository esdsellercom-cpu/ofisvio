<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bildirim alıcısı: kanal + adres (telefon/e-posta) ya da uygulama içi kullanıcı; grup üyeliği. */
class NotificationRecipient extends Model
{
    protected $fillable = ['name', 'channel', 'address', 'user_id', 'group', 'location_id', 'is_active', 'updated_by'];

    protected $casts = ['is_active' => 'boolean'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Panelde maskeli adres (PII). */
    public function maskedAddress(): string
    {
        $a = (string) $this->address;

        if ($this->channel === 'in_app') {
            return $this->user->name ?? '—';
        }

        if (str_contains($a, '@')) {
            [$u, $d] = explode('@', $a, 2);

            return mb_substr($u, 0, 2).'***@'.$d;
        }

        return strlen($a) > 6 ? substr($a, 0, 4).str_repeat('*', strlen($a) - 6).substr($a, -2) : '***';
    }
}
