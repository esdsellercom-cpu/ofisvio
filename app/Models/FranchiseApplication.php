<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Franchise başvurusu (faz 39e): vitrin formundan gelir, panelde değerlendirilir.
 * BelongsToTenant KULLANMAZ — başvuru henüz hiçbir organizasyona ait değildir (Lead gibi).
 */
class FranchiseApplication extends Model
{
    public const STATUSES = ['new' => 'Yeni', 'reviewing' => 'İnceleniyor', 'meeting' => 'Görüşme', 'positive' => 'Olumlu', 'negative' => 'Olumsuz', 'archived' => 'Arşiv'];

    /** Durum rozeti rengi (panel). */
    public const STATUS_TONE = ['new' => 'a', 'reviewing' => 'i', 'meeting' => 'w', 'positive' => 'g', 'negative' => 'c', 'archived' => 'n'];

    /** Bütçe aralıkları — başvuranın beyanı, seçenek listesi (ticari veri değil). */
    public const BUDGETS = ['500k_alti' => '500 bin ₺ altı', '500k_1m' => '500 bin – 1 milyon ₺', '1m_2m' => '1 – 2 milyon ₺', '2m_ustu' => '2 milyon ₺ üzeri', 'belirsiz' => 'Henüz belirlemedim'];

    protected $fillable = ['number', 'name', 'first_name', 'last_name', 'company', 'email', 'phone', 'city', 'district', 'budget', 'experience', 'message', 'status', 'assigned_to', 'internal_note', 'handled_at', 'consented_at', 'consent_ip'];

    public function budgetLabel(): string
    {
        return self::BUDGETS[$this->budget] ?? (string) ($this->budget ?? '—');
    }

    protected $casts = ['consented_at' => 'datetime', 'handled_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
