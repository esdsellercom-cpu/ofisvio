<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Support\AccessDecision;
use Illuminate\Support\Facades\DB;

/**
 * RBAC + Scope çekirdeği.
 *
 * ÖNCEKİ SÜRÜMDEKİ KRİTİK HATA (düzeltildi):
 * Eski can() yalnızca user_roles'taki company_id/organization_id/location_id
 * kolonlarını context ile karşılaştırıyor, role_permissions.scope kolonunu HİÇ
 * okumuyordu. Sonuç: global rolü olan super_admin/system_admin'in user_roles
 * kaydında bu kolonlar NULL olduğu için, context'te company_id geçen her
 * kontrol (yani pratikte tüm controller çağrıları) false dönüyordu — yetkili
 * yönetici kendi paneline giremezdi. Bu dosya artık scope'u gerçek karar
 * kaynağı olarak kullanıyor.
 *
 * Kapsam kuralları (kasıtlı olarak dar):
 *   global       -> context'ten bağımsız geçerli, ANCAK yalnızca user_roles
 *                   kaydının üç kapsam kolonu da NULL ise. Yanlışlıkla bir
 *                   şirkete bağlanmış super_admin ataması global olmaz.
 *   organization -> context organizasyonu doğrudan vermeli VEYA context'teki
 *                   şirket o organizasyona ait olmalı (kapsam içerme).
 *   company      -> context şirketi vermeli ve birebir eşleşmeli.
 *   location     -> context lokasyonu vermeli ve birebir eşleşmeli.
 *
 * Genişletme ASLA ters yönde çalışmaz: company kapsamlı bir rol, organization
 * veya global bir kontrolü karşılayamaz. Context'te ilgili anahtar yoksa
 * o kapsamdaki izin verilmez (fail-closed) — böylece controller'ın context
 * geçirmeyi unutması yetki açığına değil, reddedilmeye yol açar.
 */
class AuthorizationService
{
    /**
     * @param  array{company_id?: int|null, organization_id?: int|null, location_id?: int|null}  $context
     */
    public function can(User $user, string $permissionName, array $context = []): bool
    {
        return $this->resolve($user, $permissionName, $context)->granted;
    }

    /**
     * Bu izin, bu kullanıcı için, bu context'te JIT onayı gerektiriyor mu?
     *
     * Eski sürüm context'i hiç dikkate almıyordu ve kullanıcının izni taşıyan
     * HERHANGİ bir rolünde requires_jit=true varsa true dönüyordu. Artık karar
     * gerçekten uygulanacak grant üzerinden veriliyor: kullanıcı aynı izne hem
     * JIT'li hem JIT'siz bir yoldan sahipse, JIT'siz yol kazanır.
     */
    public function requiresJit(User $user, string $permissionName, array $context = []): bool
    {
        return $this->resolve($user, $permissionName, $context)->requiresJit;
    }

    /**
     * Tam karar — audit log ve JIT akışı bunu kullanır.
     */
    public function resolve(User $user, string $permissionName, array $context = []): AccessDecision
    {
        $permission = Permission::where('name', $permissionName)->first();

        if (! $permission) {
            // Fail-closed: tanımsız izin adı = izin yok. Yazım hatası olan bir
            // permission adının sessizce "izin ver"e dönüşmesi en tehlikeli hata.
            return AccessDecision::denied('unknown_permission');
        }

        $companyId = $this->intOrNull($context['company_id'] ?? null);
        $locationId = $this->intOrNull($context['location_id'] ?? null);
        $organizationId = $this->resolveOrganizationId($context, $companyId);

        $rows = DB::table('user_roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->id)
            ->where('user_roles.status', 'active')
            ->where('role_permissions.permission_id', $permission->id)
            ->select([
                'user_roles.id as user_role_id',
                'user_roles.company_id',
                'user_roles.organization_id',
                'user_roles.location_id',
                'role_permissions.scope',
                'role_permissions.requires_jit',
                'role_permissions.requires_dual_control',
            ])
            // JIT gerektirmeyen grant varsa önce o değerlendirilsin.
            ->orderBy('role_permissions.requires_jit')
            ->get();

        $jitFallback = null;

        foreach ($rows as $row) {
            if (! $this->scopeMatches($row, $companyId, $organizationId, $locationId)) {
                continue;
            }

            $decision = AccessDecision::granted(
                $row->scope,
                (int) $row->user_role_id,
                (bool) $row->requires_jit,
                (bool) $row->requires_dual_control
            );

            if (! $decision->requiresJit) {
                return $decision; // JIT'siz yol bulundu, en iyi sonuç.
            }

            $jitFallback ??= $decision;
        }

        return $jitFallback ?? AccessDecision::denied('no_matching_scope');
    }

    /**
     * Kapsam eşleşmesi. Her dal kendi context anahtarının VARLIĞINI şart koşar;
     * eksik context sessizce geniş yetkiye dönüşmez.
     */
    private function scopeMatches(object $row, ?int $companyId, ?int $organizationId, ?int $locationId): bool
    {
        return match ($row->scope) {
            'global' => $row->company_id === null
                && $row->organization_id === null
                && $row->location_id === null,

            'organization' => $organizationId !== null
                && (int) $row->organization_id === $organizationId,

            'company' => $companyId !== null
                && (int) $row->company_id === $companyId,

            'location' => $locationId !== null
                && (int) $row->location_id === $locationId,

            default => false, // tanımsız scope değeri = reddet
        };
    }

    /**
     * Context organizasyonu doğrudan veriyorsa onu kullan; yalnızca şirket
     * veriyorsa şirketin bağlı olduğu organizasyona genişlet (kapsam içerme).
     * Bu genişleme tek yönlüdür ve URL'den gelen değere değil, DB'deki
     * companies.organization_id kaydına dayanır.
     */
    private function resolveOrganizationId(array $context, ?int $companyId): ?int
    {
        $explicit = $this->intOrNull($context['organization_id'] ?? null);

        if ($explicit !== null) {
            return $explicit;
        }

        if ($companyId === null) {
            return null;
        }

        // withoutTenantScope ZORUNLU: TenantScope, companies tablosunu AKTİF
        // organizasyona göre filtreler. Burada ise organizasyonu şirketten
        // ÖĞRENMEYE çalışıyoruz — scope'u uygulamak döngüsel bir bağımlılık
        // kurar ve organization kapsamlı izinler hiçbir zaman eşleşmez.
        // Bu sorgu yetki vermez, yalnızca şirketin hangi organizasyona ait
        // olduğunu okur; tenant kararı çağıran katmanda verilir.
        $orgId = Company::withoutTenantScope()->whereKey($companyId)->value('organization_id');

        return $orgId !== null ? (int) $orgId : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
