<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Siteden gelen talep.
 *
 * BelongsToTenant KULLANMAZ — kasıtlı. Bir lead henüz hiçbir organizasyona ait
 * değildir; zaten "organizasyon olmadan önceki" aşamadır. Tenant scope
 * uygulansaydı fail-closed davranış gereği hiçbir lead kaydedilemez ve
 * okunamazdı. Lead'lere erişim RBAC ile korunur (crm modülü).
 */
class Lead extends Model
{
    protected $fillable = [
        'kind', 'name', 'email', 'phone', 'location_id', 'solution', 'team_size',
        'requested_date', 'requested_slot', 'note',
        'consented_at', 'consent_ip', 'consent_user_agent', 'status',
    ];

    public const STATUSES = ['new' => 'Yeni', 'contacted' => 'İletişime geçildi', 'won' => 'Kazanıldı', 'lost' => 'Kaybedildi'];

    protected $casts = [
        'consented_at' => 'datetime',
        'requested_date' => 'date',
        'handled_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
