<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UserRole extends Model
{
    protected $fillable = [
        'user_id', 'role_id', 'company_id', 'organization_id', 'location_id', 'status',
    ];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Üye profili (faz 51).
     *
     * @return HasOne<MemberProfile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(MemberProfile::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
