<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Şirket üyeliği (faz 39b): company_id tenant sınırı. Durum yalnız
 * SubscriptionService yazar (create/cancel/renew/expireStale).
 */
class Subscription extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['active' => 'Aktif', 'expired' => 'Süresi doldu', 'cancelled' => 'İptal'];

    protected $fillable = [
        'company_id', 'plan_id', 'location_id', 'status', 'starts_on', 'ends_on', 'price', 'period',
        'auto_renew', 'note', 'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'starts_on' => 'date', 'ends_on' => 'date', 'cancelled_at' => 'datetime',
        'price' => 'integer', 'auto_renew' => 'boolean',
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Aktif ve bitişine 30 gün (ya da verilen gün) kaldı. */
    public function expiresWithin(int $days = 30): bool
    {
        return $this->isActive() && $this->ends_on->lte(Carbon::today()->addDays($days));
    }

    public function daysLeft(): int
    {
        return (int) Carbon::today()->diffInDays($this->ends_on, false);
    }

    public function monthlyPrice(): int
    {
        return $this->period === 'yearly' ? intdiv($this->price, 12) : $this->price;
    }
}
