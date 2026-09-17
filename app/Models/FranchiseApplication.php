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
    public const STATUSES = ['new' => 'Yeni', 'reviewing' => 'Değerlendirmede', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi'];

    protected $fillable = ['name', 'email', 'phone', 'city', 'district', 'budget', 'experience', 'message', 'status', 'assigned_to', 'internal_note', 'handled_at', 'consented_at', 'consent_ip'];

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
