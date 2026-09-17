<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use DomainException;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
    public function __construct(private readonly PasswordBroker $passwords, private readonly AuditService $audit) {}

    /**
     * Liste: ad/e-posta araması + sayfalama.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(?string $search = null, int $perPage = 50): LengthAwarePaginator
    {
        $search = trim((string) $search);

        return User::query()
            ->with(['userRoles.role', 'organizationMemberships.organization'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(int $id): User
    {
        return User::query()
            ->with(['userRoles.role', 'organizationMemberships.organization'])
            ->findOrFail($id);
    }

    /**
     * Aktif global (personel) rolü olan kullanıcılar — atama listeleri için.
     *
     * @return Collection<int, User>
     */
    public function staff(): Collection
    {
        return User::query()
            ->whereHas('userRoles', fn ($q) => $q->where('status', 'active')->whereNull('company_id')->whereNull('organization_id')->whereNull('location_id')->whereHas('role', fn ($r) => $r->where('type', 'internal')))
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Role> */
    public function internalRoles(): Collection
    {
        return Role::query()->where('type', 'internal')->orderBy('name')->get();
    }

    /**
     * Matriste yalnız lokasyon kapsamlı izin taşıyan internal roller (resepsiyon,
     * lokasyon yöneticisi): global atama hiçbir izne eşleşmez, lokasyon ZORUNLU.
     *
     * @return array<int, string>
     */
    public function locationScopedRoles(): array
    {
        return Role::query()->where('type', 'internal')
            ->whereHas('permissions', fn ($q) => $q->where('role_permissions.scope', 'location'))
            ->whereDoesntHave('permissions', fn ($q) => $q->where('role_permissions.scope', 'global'))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * Personel daveti: kullanıcı yoksa oluşturulur (bilinmeyen şifre) ve sıfırlama
     * bağlantısı gider; varsa yalnızca rol atanır.
     *
     * @param  array{name: string, email: string, role: string, location_id?: int|null}  $data
     * @return array{user: User, created: bool, invited: bool}
     */
    public function inviteStaff(User $actor, array $data): array
    {
        $role = $this->internalRole($data['role']);
        $locationId = $this->locationFor($role, $data['location_id'] ?? null);
        $email = Str::lower(trim($data['email']));

        $result = DB::transaction(function () use ($data, $email, $role, $locationId) {
            $user = User::query()->where('email', $email)->first();
            $created = false;

            if ($user === null) {
                $user = User::create(['name' => trim($data['name']), 'email' => $email, 'password' => Str::random(64)]);
                $created = true;
            }

            $this->grant($user, $role, $locationId);

            return ['user' => $user, 'created' => $created];
        });

        $invited = $result['created'] && $this->passwords->sendResetLink(['email' => $email]) === PasswordBroker::RESET_LINK_SENT;

        $this->audit->record($actor, 'staff.invited', 'user', $result['user']->id, [], ['email' => $email, 'role' => $role->name, 'location_id' => $locationId, 'created' => $result['created'], 'invited' => $invited]);

        return ['user' => $result['user'], 'created' => $result['created'], 'invited' => $invited];
    }

    public function assignInternalRole(User $actor, User $user, string $roleName, ?int $locationId = null): UserRole
    {
        $role = $this->internalRole($roleName);

        $userRole = $this->grant($user, $role, $this->locationFor($role, $locationId));
        $this->audit->record($actor, 'role.assigned', 'user_role', $userRole->id, [], ['user_id' => $user->id, 'role' => $role->name, 'location_id' => $userRole->location_id]);

        return $userRole;
    }

    /** Lokasyon kapsamlı rol lokasyon ister, global rol lokasyon almaz. */
    private function locationFor(Role $role, ?int $locationId): ?int
    {
        $scoped = in_array($role->name, $this->locationScopedRoles(), true);

        if ($scoped && $locationId === null) {
            throw new DomainException(__('roles.'.$role->name).' lokasyon kapsamlıdır; bir şube seçin.');
        }

        if (! $scoped && $locationId !== null) {
            throw new DomainException(__('roles.'.$role->name).' global bir roldür; lokasyon atanmaz.');
        }

        if ($locationId !== null && ! Location::query()->whereKey($locationId)->exists()) {
            throw new DomainException('Şube bulunamadı.');
        }

        return $locationId;
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
        $this->audit->record($actor, 'role.suspended', 'user_role', $userRole->id, ['status' => 'active'], ['status' => 'suspended', 'user_id' => $userRole->user_id, 'role_id' => $userRole->role_id]);
    }

    public function reactivateRole(UserRole $userRole): void
    {
        $userRole->status = 'active';
        $userRole->save();
        $this->audit->record(null, 'role.reactivated', 'user_role', $userRole->id, ['status' => 'suspended'], ['status' => 'active', 'user_id' => $userRole->user_id, 'role_id' => $userRole->role_id]);
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

    /** Global atama = üç kapsam kolonu NULL (lokasyon rolünde location_id dolu); varsa yeniden etkinleştirilir. */
    private function grant(User $user, Role $role, ?int $locationId = null): UserRole
    {
        $userRole = UserRole::query()->firstOrNew([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'company_id' => null,
            'organization_id' => null,
            'location_id' => $locationId,
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
