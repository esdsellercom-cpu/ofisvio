<?php

namespace App\Support;

/**
 * Tek bir yetki sorusunun tam cevabı. can() sadece ->granted döner,
 * ama audit log ve JIT akışı "neden" bilgisine de ihtiyaç duyar —
 * bu yüzden karar bir bool değil, bir değer nesnesi olarak taşınır.
 */
final class AccessDecision
{
    private function __construct(
        public readonly bool $granted,
        public readonly bool $requiresJit,
        public readonly bool $requiresDualControl,
        public readonly ?string $scope,
        public readonly ?int $userRoleId,
        public readonly string $reason,
    ) {}

    public static function granted(string $scope, int $userRoleId, bool $requiresJit, bool $requiresDualControl = false): self
    {
        return new self(
            true,
            $requiresJit,
            $requiresDualControl,
            $scope,
            $userRoleId,
            $requiresJit ? 'granted_pending_jit' : 'granted'
        );
    }

    /** Fail-closed: reddedilen her karar JIT gerektiriyormuş gibi işaretlenir ki
     *  çağıran kod yanlışlıkla "JIT gerekmiyor, doğrudan geç" sonucuna varmasın. */
    public static function denied(string $reason): self
    {
        return new self(false, true, true, null, null, $reason);
    }
}
