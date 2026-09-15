<?php

namespace App\Models\Concerns;

use App\Models\OrganizationMember;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mevcut App\Models\User sınıfınıza EKLEYİN (dosyayı değiştirmeyin, trait'i
 * use edin):
 *
 *   class User extends Authenticatable
 *   {
 *       use HasTenantRoles;
 *   }
 *
 * Kasıtlı olarak User.php'yi bu pakete koymuyoruz: Laravel'in kendi User
 * modelinin üzerine yazmak, projedeki auth/notification ayarlarını sessizce
 * siler.
 */
trait HasTenantRoles
{
    /** @return HasMany<OrganizationMember, $this> */
    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /** @return HasMany<UserRole, $this> */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }
}
