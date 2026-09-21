<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserRole;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Personel/kullanıcı çıkışı (audit F-26): tek işlemde hesap askıya alınır (giriş reddi), tüm rolleri askıya (yetki
 * kaybı), açık JIT grant'leri iptal, açık oturumlar ve "beni hatırla" düşürülür, API/webhook secret'ları kişiye bağlı
 * olmadığından dokunulmaz (kurulum genelidir; gerekirse rotasyon ayrıca). Her adım audit'e düşer. Son aktif süper
 * yönetici çıkarılamaz; kişi kendini çıkaramaz. Geri dönüş: hesap yeniden etkinleştirme + rol atama (manuel, bilinçli).
 */
class OffboardingService
{
    public function __construct(private readonly AccountSecurityService $accounts, private readonly UserAdminService $roles, private readonly JitAccessService $jit, private readonly AuditService $audit) {}

    /** @return array{roles: int, grants: int, sessions: int} */
    public function offboard(User $actor, User $target, string $reason): array
    {
        if ((int) $actor->id === (int) $target->id) {
            throw new DomainException('Kendi hesabınızı çıkaramazsınız.');
        }

        return DB::transaction(function () use ($actor, $target, $reason) {
            $roles = 0;

            foreach (UserRole::query()->where('user_id', $target->id)->where('status', 'active')->with('role')->get() as $userRole) {
                $this->roles->suspendRole($actor, $userRole); // son süper yönetici koruması burada
                $roles++;
            }

            $grants = 0;

            foreach ($this->jit->activeGrantsFor($target) as $grant) {
                $this->jit->revoke((int) $grant->id);
                $grants++;
            }

            $sessions = $this->accounts->terminateSessions($actor, $target);

            if ($target->status !== 'suspended') {
                $this->accounts->suspend($actor, $target, 'Çıkış: '.$reason);
            }

            $summary = ['roles' => $roles, 'grants' => $grants, 'sessions' => $sessions];
            $this->audit->record($actor, 'user.offboarded', 'user', $target->id, [], $summary + ['reason' => mb_substr($reason, 0, 200)]);

            return $summary;
        });
    }
}
