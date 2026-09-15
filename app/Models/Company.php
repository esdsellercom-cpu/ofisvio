<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /** Şirketin tenant sınırı organizasyondur. */
    protected string $tenantColumn = 'organization_id';

    protected $fillable = ['organization_id', 'legal_name', 'tax_number', 'status'];

    protected $casts = ['status' => CompanyStatus::class];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<KycDocument, $this> */
    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    /** @return HasMany<CompanyStatusTransition, $this> */
    public function statusTransitions(): HasMany
    {
        return $this->hasMany(CompanyStatusTransition::class);
    }

    /**
     * Şirket kapsamlı rol atamaları. Route'larda {userRole} scopeBindings ile
     * bu ilişki üzerinden çözülür — başka şirketin rol kaydı bu şirketin
     * URL'sine takılmaz.
     *
     * @return HasMany<UserRole, $this>
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }
}
