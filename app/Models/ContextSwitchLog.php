<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContextSwitchLog extends Model
{
    public const PATH_MEMBERSHIP = 'membership';

    public const PATH_STAFF = 'staff';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'from_organization_id', 'to_organization_id', 'entry_path', 'ip_address', 'switched_at',
    ];

    protected $casts = ['switched_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function toOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'to_organization_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function fromOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'from_organization_id');
    }
}
