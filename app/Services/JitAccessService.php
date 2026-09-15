<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\User;
use App\Support\AccessDecision;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Just-In-Time erişim — V5 bölüm 3 ("Super Admin != Root").
 *
 * requires_jit=true olan bir izin, rolde TANIMLI olması yetkiyi VERMEZ;
 * yalnızca "talep etme hakkı" verir. Fiili erişim, gerekçeli ve süreli bir
 * jit_access_grants kaydı ile açılır.
 *
 * DUAL-CONTROL: requires_dual_control=true olan izinlerde grant, talep eden
 * kişinin kendisi tarafından açılamaz — ikinci bir yetkili onaylamalıdır.
 * Kural basit ama kritiktir: onaylayan, talep edenden FARKLI bir kişi olmalı
 * ve onaylayanın da aynı izne yetkisi bulunmalıdır. İkisi de kontrol edilmezse
 * "dual-control" yalnızca doldurulmuş bir kolondan ibaret kalır.
 */
class JitAccessService
{
    /** Varsayılan grant süresi (dakika). Kısa tutmak kasıtlıdır. */
    public const DEFAULT_TTL_MINUTES = 30;

    /** Üst sınır: çağıran ne isterse istesin bir grant bundan uzun yaşamaz. */
    public const MAX_TTL_MINUTES = 240;

    public function __construct(private readonly AuthorizationService $authorization) {}

    /**
     * Nihai kapı: RBAC + scope YETERLİ Mİ, ve gerekiyorsa JIT AÇIK MI?
     * Controller/middleware bu metodu çağırmalı, can()'i değil.
     */
    public function allows(
        User $user,
        string $permissionName,
        array $context,
        ?string $resourceType = null,
        ?int $resourceId = null,
    ): bool {
        $decision = $this->authorization->resolve($user, $permissionName, $context);

        if (! $decision->granted) {
            return false;
        }

        if (! $decision->requiresJit) {
            return true;
        }

        // JIT gerekiyor ama hangi kaynak için sorulduğu belirtilmemiş:
        // kaynak bazlı grant doğrulanamayacağı için reddet (fail-closed).
        if ($resourceType === null || $resourceId === null) {
            return false;
        }

        return $this->hasActiveGrant($user, $permissionName, $resourceType, $resourceId);
    }

    /**
     * Kullanıcının şu AN bu kaynağa açık, süresi dolmamış, iptal edilmemiş
     * bir JIT grant'i var mı?
     */
    public function hasActiveGrant(User $user, string $permissionName, string $resourceType, int $resourceId): bool
    {
        $permissionId = Permission::where('name', $permissionName)->value('id');

        if ($permissionId === null) {
            return false; // fail-closed
        }

        return DB::table('jit_access_grants')
            ->where('user_id', $user->id)
            ->where('permission_id', $permissionId)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Gerekçeli, süreli grant açar.
     *
     * @throws RuntimeException dual-control ihlalinde. Bilinçli olarak
     *                          istisna atıyoruz, null dönmüyoruz: "onaysız imha denemesi"
     *                          sessizce yutulacak bir olay değildir.
     */
    public function grant(
        User $user,
        string $permissionName,
        array $context,
        string $resourceType,
        int $resourceId,
        string $reason,
        ?User $approvedBy = null,
        int $ttlMinutes = self::DEFAULT_TTL_MINUTES,
    ): ?int {
        $decision = $this->authorization->resolve($user, $permissionName, $context);

        if (! $decision->granted) {
            return null; // izni rolde taşımıyor — JIT bunu telafi etmez
        }

        if (trim($reason) === '') {
            return null; // gerekçesiz grant audit değeri taşımaz
        }

        $this->assertDualControl($decision, $user, $permissionName, $context, $approvedBy);

        $ttl = max(1, min($ttlMinutes, self::MAX_TTL_MINUTES));
        $permissionId = Permission::where('name', $permissionName)->value('id');

        return (int) DB::table('jit_access_grants')->insertGetId([
            'user_id' => $user->id,
            'permission_id' => $permissionId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'reason' => trim($reason),
            'granted_at' => now(),
            'expires_at' => now()->addMinutes($ttl),
            'approved_by' => $approvedBy?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Dual-control kuralları. Üçü birden sağlanmalı:
     *   1. Onaylayan VAR
     *   2. Onaylayan, talep edenden FARKLI bir kişi
     *   3. Onaylayanın da aynı izne yetkisi var
     *
     * 3. madde olmadan kural anlamsızlaşır: herhangi bir kullanıcıyı
     * "onaylayan" olarak yazmak imhayı mümkün kılardı.
     */
    private function assertDualControl(
        AccessDecision $decision,
        User $user,
        string $permissionName,
        array $context,
        ?User $approvedBy,
    ): void {
        if (! $decision->requiresDualControl) {
            return;
        }

        if ($approvedBy === null) {
            throw new RuntimeException(
                "'{$permissionName}' dual-control gerektirir: ikinci bir yetkilinin onayı zorunludur."
            );
        }

        if ((int) $approvedBy->id === (int) $user->id) {
            throw new RuntimeException(
                "'{$permissionName}' dual-control gerektirir: talep eden kendi talebini onaylayamaz."
            );
        }

        if (! $this->authorization->can($approvedBy, $permissionName, $context)) {
            throw new RuntimeException(
                "'{$permissionName}' dual-control gerektirir: onaylayan kullanıcının da bu izne yetkisi olmalı."
            );
        }
    }

    public function revoke(int $grantId): void
    {
        DB::table('jit_access_grants')
            ->where('id', $grantId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Süresi dolmuş grant'leri temizlemek YERİNE işaretlemeyi tercih ediyoruz:
     * jit_access_grants bir AUDIT tablosudur, silinmez. Bu metod yalnızca
     * "şu an açık olan" grant'leri listelemek için.
     */
    public function activeGrantsFor(User $user): array
    {
        return DB::table('jit_access_grants')
            ->join('permissions', 'permissions.id', '=', 'jit_access_grants.permission_id')
            ->where('jit_access_grants.user_id', $user->id)
            ->whereNull('jit_access_grants.revoked_at')
            ->where('jit_access_grants.expires_at', '>', now())
            ->select('jit_access_grants.*', 'permissions.name as permission_name')
            ->get()
            ->all();
    }
}
