<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Şirket (Company) — müşteri organizasyonunun altındaki tüzel kişilik.
 *
 * Yetki KARARI burada verilmez (route middleware'i verir); ama LİSTELEME
 * burada süzülür: bir organizasyonun altındaki şirketlerden kullanıcının
 * company.view taşımadıkları listede görünmez. Tenant scope organizasyon
 * sınırını çizer, şirket sınırını çizmez — kardeş şirketleri ayırmak bu
 * servisin işidir (bkz. TenantScope başlığındaki SINIR notu).
 */
class CompanyService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $context,
    ) {}

    /**
     * Aktif organizasyondaki, kullanıcının görebildiği şirketler.
     *
     * Personel (kyc.view_status global) hepsini görür; müşteri kullanıcısı
     * yalnızca company.view taşıdığı şirketleri görür.
     *
     * @return Collection<int, Company>
     */
    public function visibleTo(User $user): Collection
    {
        $organizationId = $this->context->requireOrganization($user)->id;
        $companies = Company::query()->orderBy('legal_name')->get();

        if ($this->authorization->can($user, 'kyc.view_status', ['organization_id' => $organizationId])) {
            return $companies;
        }

        return $companies->filter(fn (Company $company) => $this->authorization->can(
            $user,
            'company.view',
            ['organization_id' => $organizationId, 'company_id' => $company->id],
        ))->values();
    }

    /**
     * Aktif organizasyona yeni şirket açar ve açan kullanıcıya o şirket
     * için owner rolü atar. Yetki: organization.manage (route'ta).
     *
     * Owner ataması ZORUNLU: company kapsamlı izinler (kyc.view, kyc.upload)
     * user_roles.company_id ile birebir eşleşir; atama olmazsa şirketi açan
     * kişi kendi şirketine belge yükleyemez.
     *
     * @param  array{legal_name: string, tax_number?: string|null}  $data
     */
    public function create(User $creator, array $data): Company
    {
        $organization = $this->context->requireOrganization($creator);

        return DB::transaction(function () use ($organization, $creator, $data) {
            $company = Company::create([
                'organization_id' => $organization->id,
                'legal_name' => $data['legal_name'],
                'tax_number' => $data['tax_number'] ?? null,
                'status' => CompanyStatus::REGISTERED,
            ]);

            $this->assignOwner($creator, $company);

            return $company;
        });
    }

    /**
     * Künye (company.update): unvan ve vergi no. Durum BURADAN değişmez
     * (CompanyActivationService); tenant doğrulaması route+middleware'de.
     *
     * @param  array{legal_name: string, tax_number?: string|null}  $data
     */
    public function updateProfile(Company $company, array $data): Company
    {
        $company->legal_name = trim($data['legal_name']);
        $company->tax_number = ($data['tax_number'] ?? null) !== null && trim((string) $data['tax_number']) !== '' ? trim((string) $data['tax_number']) : null;
        $company->save();

        return $company;
    }

    /**
     * Şirket seviyesinde owner rolü. Personel yolundan açılan şirketlerde
     * (onboarding) owner müşteri kullanıcısıdır, personel değil.
     */
    public function assignOwner(User $user, Company $company): void
    {
        $roleId = Role::query()->where('name', 'owner')->value('id');

        if ($roleId === null) {
            throw new RuntimeException('owner rolü seed edilmemiş — RolePermissionSeeder çalıştırılmalı.');
        }

        UserRole::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'company_id' => $company->id,
            'organization_id' => null,
            'location_id' => null,
        ], ['status' => 'active']);
    }

    /**
     * Organizasyon seviyesinde owner rolü (organization.view / organization.manage).
     */
    public function assignOrganizationOwner(User $user, Organization $organization): void
    {
        $roleId = Role::query()->where('name', 'owner')->value('id');

        if ($roleId === null) {
            throw new RuntimeException('owner rolü seed edilmemiş — RolePermissionSeeder çalıştırılmalı.');
        }

        UserRole::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'company_id' => null,
            'organization_id' => $organization->id,
            'location_id' => null,
        ], ['status' => 'active']);
    }
}
