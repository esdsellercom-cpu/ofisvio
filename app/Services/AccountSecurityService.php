<?php

namespace App\Services;

use App\Models\LoginEvent;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hesap güvenliği (audit: oturum yönetimi, giriş geçmişi, hesap durumu).
 *
 * - Aktif oturumlar `sessions` tablosundan (SESSION_DRIVER=database; başka sürücüde liste yok).
 * - "Diğer cihazlardan çıkış": kullanıcının diğer oturum satırları silinir + remember token döndürülür.
 * - Askıya alma: users.status = suspended → giriş reddedilir (FortifyServiceProvider), açık oturumlar
 *   kesilir (EnsureAccountActive + satır silme). Her adım audit.
 */
class AccountSecurityService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return array<int, array{id: string, ip: string|null, user_agent: string|null, last_activity: Carbon, current: bool}>|null null = sürücü desteklemiyor
     */
    public function activeSessions(User $user, ?string $currentSessionId): ?array
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        return DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->orderByDesc('last_activity')->get()
            ->map(fn (object $row) => [
                'id' => (string) $row->id,
                'ip' => $row->ip_address !== null ? (string) $row->ip_address : null,
                'user_agent' => $row->user_agent !== null ? (string) $row->user_agent : null,
                'last_activity' => Carbon::createFromTimestamp((int) $row->last_activity),
                'current' => (string) $row->id === $currentSessionId,
            ])->all();
    }

    /** Kullanıcının kendi diğer oturumları: satırlar silinir, remember token döner. */
    public function logoutOtherDevices(User $user, ?string $currentSessionId): int
    {
        $n = $this->deleteSessions($user, $currentSessionId);
        $user->setRememberToken(Str::random(60));
        $user->save();
        $this->audit->record($user, 'user.other_devices_logout', 'user', $user->id, [], ['sessions_closed' => $n]);

        return $n;
    }

    /** Yönetici: hedef kullanıcının TÜM oturumları (user.manage). */
    public function terminateSessions(User $actor, User $target): int
    {
        $n = $this->deleteSessions($target, null);
        $target->setRememberToken(Str::random(60));
        $target->save();
        $this->audit->record($actor, 'user.sessions_terminated', 'user', $target->id, [], ['sessions_closed' => $n]);

        return $n;
    }

    public function suspend(User $actor, User $target, string $reason): User
    {
        if ($actor->id === $target->id) {
            throw new DomainException('Kendi hesabınızı askıya alamazsınız.');
        }

        if ($target->isSuspended()) {
            throw new DomainException('Hesap zaten askıda.');
        }

        $before = $target->only(['status', 'suspended_at', 'suspended_reason']);
        $target->forceFill(['status' => 'suspended', 'suspended_at' => Carbon::now(), 'suspended_reason' => $reason])->save();
        $this->deleteSessions($target, null);
        $target->setRememberToken(Str::random(60));
        $target->save();
        $this->audit->record($actor, 'user.suspended', 'user', $target->id, $before, $target->only(['status', 'suspended_at', 'suspended_reason']));

        return $target;
    }

    public function reactivate(User $actor, User $target): User
    {
        if (! $target->isSuspended()) {
            throw new DomainException('Hesap askıda değil.');
        }

        $before = $target->only(['status', 'suspended_at', 'suspended_reason']);
        $target->forceFill(['status' => 'active', 'suspended_at' => null, 'suspended_reason' => null])->save();
        $this->audit->record($actor, 'user.reactivated', 'user', $target->id, $before, $target->only(['status']));

        return $target;
    }

    /**
     * Giriş geçmişi (kullanıcının kendisi ya da yönetici).
     *
     * @return Collection<int, LoginEvent>
     */
    public function loginHistory(User $user, int $limit = 10): Collection
    {
        return LoginEvent::query()->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('email', $user->email))->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
    }

    private function deleteSessions(User $user, ?string $keepSessionId): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)
            ->when($keepSessionId !== null, fn ($q) => $q->where('id', '!=', $keepSessionId))->delete();
    }
}
