<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Üyelik paketi (faz 39b). Panelden yönetilir; kodda/seed'de paket yok. */
class Plan extends Model
{
    public const PERIODS = ['monthly' => 'Aylık', 'yearly' => 'Yıllık'];

    protected $fillable = ['name', 'slug', 'summary', 'features', 'price', 'period', 'service_id', 'space_kind', 'is_active', 'sort_order', 'updated_by'];

    protected $casts = ['price' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function periodLabel(): string
    {
        return self::PERIODS[$this->period] ?? $this->period;
    }

    /** @return array<int, string> */
    public function featureList(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $this->features))));
    }

    /** Aylık normalize tutar (MRR). */
    public function monthlyPrice(): int
    {
        return $this->period === 'yearly' ? intdiv($this->price, 12) : $this->price;
    }
}
