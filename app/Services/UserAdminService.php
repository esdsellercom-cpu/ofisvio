<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use DomainException;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kullanıcı yönetimi (faz 29, user.manage — system_admin/super_admin, global).
 *
 * Personel daveti ve GLOBAL (internal) rol atama/askıya alma. Müşteri rolleri
 * (company/organization kapsamlı) buradan atanmaz — onlar MembershipService ve
 * OrganizationOnboardingService'in işidir; burada yalnızca görüntülenir.
 *
 * Şifre hiçbir zaman personelce belirlenmez: davet = sıfırlama bağlantısı.
 * Kendini kilitleme savunması: kişi kendi rolünü askıya alamaz; son aktif
 * super_admin askıya alınamaz.
 */
class UserAdminService
{
    public function __construct(private readonly PasswordBroker $passwords) {}

    /** @return Collection<int, User> */
    public function all(): Collection
    {
        return User::query()
            ->with(['userRoles.role', 'organizationMemberships.organization'])
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): User
    {
        return User::query()
            ->with(['userRoles.role', 'organizationMemberships.organization'])
            ->findOrFail($id);
    }

    /** @return Collection<int, Role> */
    public function internalRoles(): Collection
    {
        return Role::query()->where('type', 'internal')->orderBy('name')->get();
    }

    /**
     * Personel daveti: kullanıcı yoksa oluşturulur (bilinmeyen şifre) ve sıfırlama
     * bağlantısı gider; varsa yalnızca rol atanır.
     *
     * @param  array{name: string, email: string, role: string}  $data
     * @return array{user: User, created: bool, invited: bool}
     */
    public function inviteStaff(User $actor, array $data): array
    {
        $role = $this->internalRole($data['role']);
        $email = Str::lower(trim($data['email']));

        $result = DB::transaction(function () use ($data, $email, $role) {
            $user = User::query()->where('email', $email)->first();
            $created = false;

            if ($user === null) {
                $user = User::create(['name' => trim($data['name']), 'email' => $email, 'password' => Str::random(64)]);
                $created = true;
            }

            $this->grantGlobal($user, $role);

            return ['user' => $user, 'created' => $created];
        });

        $invited = $result['created'] && $this->passwords->sendResetLink(['email' => $email]) === PasswordBroker::RESET_LINK_SENT;

        return ['user' => $result['user'], 'created' => $result['created'], 'invited' => $invited];
    }

    public function assignInternalRole(User $actor, User $user, string $roleName): UserRole
    {
        return $this->grantGlobal($user, $this->internalRole($roleName));
    }

    public function suspendRole(User $actor, UserRole $userRole): void
    {
        if ((int) $userRole->user_id === (int) $actor->id) {
            throw new DomainException('Kendi rolünüzü askıya alamazsınız; önce başka bir yetkili atayın.');
        }

        if ($userRole->role->name === 'super_admin' && $userRole->status === 'active' && $this->activeSuperAdmins() <= 1) {
            throw new DomainException('Son aktif süper yönetici askıya alınamaz.');
        }

        $userRole->status = 'suspended';
        $userRole->save();
    }

    public function reactivateRole(UserRole $userRole): void
    {
        $userRole->status = 'active';
        $userRole->save();
    }

    /** Davet/sıfırlama bağlantısını yeniden gönderir (şifre görülmez). */
    public function resendInvite(User $user): bool
    {
        return $this->passwords->sendResetLink(['email' => $user->email]) === PasswordBroker::RESET_LINK_SENT;
    }

    private function internalRole(string $name): Role
    {
        $role = Role::query()->where('name', $name)->first();

        if ($role === null || $role->type !== 'internal') {
            throw new DomainException("'{$name}' internal (personel) tipte bir rol değil.");
        }

        return $role;
    }

    /** Global atama = üç kapsam kolonu NULL; varsa yeniden etkinleştirilir. */
    private function grantGlobal(User $user, Role $role): UserRole
    {
        $userRole = UserRole::query()->firstOrNew([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'company_id' => null,
            'organization_id' => null,
            'location_id' => null,
        ]);
        $userRole->status = 'active';
        $userRole->save();

        return $userRole;
    }

    private function activeSuperAdmins(): int
    {
        return UserRole::query()
            ->where('status', 'active')
            ->whereNull('company_id')->whereNull('organization_id')->whereNull('location_id')
            ->whereHas('role', fn ($q) => $q->where('name', 'super_admin'))
            ->count();
    }
}
