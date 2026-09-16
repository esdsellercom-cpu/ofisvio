<?php

namespace Tests\Feature\Panel;

use App\Models\Company;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use PragmaRX\Google2FA\Google2FA;

/**
 * Panel testlerinin ortak kurulumu. Fixture'lar runAsSystem içinde yaratılır:
 * TenantScope fail-closed olduğundan aktif context olmadan Company/KycDocument
 * yazmak/okumak mümkün değildir — bu, testin değil scope'un doğru çalışmasıdır.
 */
trait CreatesTenantFixtures
{
    protected function seedRbac(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    protected function organization(string $name): Organization
    {
        return Organization::create(['name' => $name, 'slug' => str($name)->slug()->toString()]);
    }

    protected function company(Organization $organization, string $legalName): Company
    {
        return app(TenantContext::class)->runAsSystem(fn () => Company::create([
            'organization_id' => $organization->id,
            'legal_name' => $legalName,
        ]));
    }

    protected function member(Organization $organization, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return $user;
    }

    /** @param  array{company_id?: int, organization_id?: int, location_id?: int}  $scope */
    protected function grantRole(User $user, string $roleName, array $scope = []): void
    {
        UserRole::create([
            'user_id' => $user->id,
            'role_id' => Role::where('name', $roleName)->value('id'),
            'company_id' => $scope['company_id'] ?? null,
            'organization_id' => $scope['organization_id'] ?? null,
            'location_id' => $scope['location_id'] ?? null,
        ]);
    }

    /** Organizasyon sahibi: üyelik + organization owner + verilen şirketlerde company owner. */
    protected function owner(Organization $organization, Company ...$companies): User
    {
        $user = $this->member($organization);
        $this->grantRole($user, 'owner', ['organization_id' => $organization->id]);

        foreach ($companies as $company) {
            $this->grantRole($user, 'owner', ['company_id' => $company->id]);
        }

        return $user;
    }

    /**
     * Ofisvio personeli: global internal rol, hiçbir organizasyona üye değil,
     * 2FA doğrulanmış (EnsureStaffTwoFactor aksi halde panele sokmaz).
     */
    protected function staff(string $roleName = 'system_admin'): User
    {
        $user = $this->staffWithoutTwoFactor($roleName);

        $user->forceFill([
            'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode([])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    /** 2FA kurmamış personel — zorunluluk testleri için. */
    protected function staffWithoutTwoFactor(string $roleName = 'system_admin'): User
    {
        $user = User::factory()->create();
        $this->grantRole($user, $roleName);

        return $user;
    }

    /** Oturumda aktif organizasyonu seçili hale getirir (üyelik/personel yolu doğrulanmaz — test kısayolu). */
    protected function withContext(Organization $organization): static
    {
        return $this->withSession([TenantContext::SESSION_KEY => $organization->id]);
    }
}
