<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Alan tahsisi (audit P0-2): company_id tenant sınırı. Durum yalnız SpaceService yazar. */
class SpaceAssignment extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['active' => 'Aktif', 'ended' => 'Sona erdi'];

    protected $fillable = ['space_id', 'company_id', 'subscription_id', 'user_id', 'status', 'starts_on', 'ends_on', 'note', 'created_by', 'ended_by', 'ended_at'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'ended_at' => 'datetime'];

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
