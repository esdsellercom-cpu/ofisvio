<?php

namespace App\Services;

use App\Models\Company;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use DomainException;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Şirket üyelikleri — kim hangi şirkette hangi şapkayla?
 *
 * Bir "üyelik" iki kayıttan oluşur ve ikisi de burada kurulur:
 *   organization_members  -> tenant'a giriş hakkı (EnsureTenantContext)
 *   user_roles(company_id)-> şirket kapsamlı yetki (AuthorizationService)
 * Yalnızca biri yazılırsa kullanıcı ya panele giremez ya da girip hiçbir
 * şey göremez; ikisi tek transaction'da yazılır.
 *
 * Rol kaldırma SİLMEZ, askıya alır: user_roles bir yetki denetim izidir.
 * Askıdaki kayıt AuthorizationService tarafından yok sayılır (status=active).
 *
 * Yetki: membership.manage (route'ta, company kapsamı).
 */
class MembershipService
{
    /** Şirkete atanabilecek roller — matristeki company tipli roller. */
    public const ASSIGNABLE_ROLES = [
        'owner', 'legal_representative', 'company_admin', 'accountant', 'employee', 'viewer',
    ];

    public function __construct(private readonly PasswordBroker $passwords) {}

    /**
     * Şirketin üyeleri: user_roles (company kapsamlı) + kullanıcı + rol.
     *
     * @return Collection<int, UserRole>
     */
    public function membersOf(Company $company): Collection
    {
        return UserRole::query()
            ->with(['user', 'role'])
            ->where('company_id', $company->id)
            ->orderByDesc('status')
            ->orderBy('id')
            ->get();
    }

    /**
     * Üye dizini (faz 39): verilen şirketlerin tüm üyeleri (company kapsamlı roller).
     * Çağıran şirket listesini CompanyService::visibleTo ile alır — görünürlük orada karar verilir.
     *
     * @param  Collection<int, Company>  $companies
     * @return Collection<int, UserRole>
     */
    public function membersOfCompanies(Collection $companies): Collection
    {
        if ($companies->isEmpty()) {
            return new Collection;
        }

        return UserRole::query()
            ->with(['user', 'role', 'company'])
            ->whereIn('company_id', $companies->modelKeys())
            ->orderBy('company_id')
            ->orderByDesc('status')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array{email: string, name: string, role: string}  $data
     * @return array{user: User, role: UserRole, invited: bool}
     */
    public function invite(User $inviter, Company $company, array $data): array
    {
        $roleName = $data['role'];

        if (! in_array($roleName, self::ASSIGNABLE_ROLES, true)) {
            throw new DomainException("'{$roleName}' bir şirket rolü değil.");
        }

        $role = Role::query()->where('name', $roleName)->where('type', 'company')->first();

        if ($role === null) {
            throw new DomainException("'{$roleName}' rolü seed edilmemiş.");
        }

        $email = Str::lower(trim($data['email']));

        $result = DB::transaction(function () use ($company, $role, $email, $data) {
            $user = User::query()->where('email', $email)->first();
            $created = false;

            if ($user === null) {
                $user = User::create([
                    'name' => trim($data['name']),
                    'email' => $email,
                    'password' => Str::random(64), // 'hashed' cast; davetli kendi şifresini belirler
                ]);
                $created = true;
            }

            // Tenant'a giriş hakkı: organizasyon üyeliği (varsa yeniden aktif).
            OrganizationMember::query()->updateOrCreate(
                ['organization_id' => $company->organization_id, 'user_id' => $user->id],
                ['status' => 'active'],
            );

            // Şirket kapsamlı rol (askıdaysa yeniden aktif).
            $userRole = UserRole::query()->updateOrCreate([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'company_id' => $company->id,
                'organization_id' => null,
                'location_id' => null,
            ], ['status' => 'active']);

            return ['user' => $user, 'role' => $userRole, 'created' => $created];
        });

        $invited = false;

        if ($result['created']) {
            $invited = $this->passwords->sendResetLink(['email' => $email]) === PasswordBroker::RESET_LINK_SENT;
        }

        return ['user' => $result['user'], 'role' => $result['role'], 'invited' => $invited];
    }

    /**
     * Rolü askıya alır. Kullanıcının başka şirketlerdeki rollerine ve
     * organizasyon üyeliğine dokunmaz — o kayıtlar başka şirketlerin işidir.
     */
    public function suspend(User $actor, Company $company, UserRole $userRole): void
    {
        if ((int) $userRole->company_id !== (int) $company->id) {
            throw new DomainException('Bu rol bu şirkete ait değil.');
        }

        // Kendini kilitleme savunması: son aktif yetkili kendi rolünü kaldıramaz.
        if ((int) $userRole->user_id === (int) $actor->id) {
            throw new DomainException('Kendi rolünüzü askıya alamazsınız; önce başka bir yetkili atayın.');
        }

        $userRole->status = 'suspended';
        $userRole->save();
    }

    public function reactivate(Company $company, UserRole $userRole): void
    {
        if ((int) $userRole->company_id !== (int) $company->id) {
            throw new DomainException('Bu rol bu şirkete ait değil.');
        }

        $userRole->status = 'active';
        $userRole->save();
    }
}
