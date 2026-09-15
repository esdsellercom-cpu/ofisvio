<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Kaynak: database/seeders/data/rbac_scope_permission_matrix.csv
 *
 * BİLİNÇLİ TASARIM KARARI: Bu seeder CSV'yi PARSE EDER, veriyi PHP içinde
 * tekrar yazmaz. CSV tek gerçek kaynak (single source of truth) kalır.
 *
 * Bu seeder REFERANS/SİSTEM verisi (roller, izinler) seed eder — bkz V5 bölüm 33
 * "CMS seed vs sahte ticari veri ayrımı". Müşteri/şirket/rezervasyon gibi ticari
 * veri ASLA bu tür bir seeder ile üretilmez.
 *
 * DENETİM SONRASI EKLENEN BÜTÜNLÜK KONTROLLERİ:
 *  - scope değeri enum'a uymalı (yazım hatası sessizce "hiçbir zaman eşleşmeyen"
 *    bir kapsama dönüşmemeli)
 *  - requires_jit yalnızca yes/no olmalı ("true"/"1" yazılırsa sessizce false
 *    olur ve JIT kapısı açık kalırdı — bu en tehlikeli sessiz hata)
 *  - company tipi rol 'global' kapsam taşıyamaz (yetki yükseltme)
 *  - internal rol 'company'/'organization' kapsam taşıyamaz (tenant sızıntısı)
 *  - aynı (rol, izin) çifti CSV'de iki kez geçemez (sessizce son satır kazanırdı)
 *  - tüm iş tek transaction'da; bir satır patlarsa yarım matris kalmaz
 */
class RolePermissionSeeder extends Seeder
{
    /** @var array<string> Ofisvio personeli (iç) rolleri */
    private const INTERNAL_ROLES = [
        'super_admin', 'system_admin', 'finance_admin',
        'operations_admin', 'reception', 'location_manager',
    ];

    /** @var array<string> Müşteri tarafı şirket rolleri */
    private const COMPANY_ROLES = [
        'owner', 'legal_representative', 'company_admin',
        'accountant', 'employee', 'viewer',
    ];

    private const VALID_SCOPES = ['company', 'organization', 'location', 'global'];

    /** Rol tipine göre YASAK kapsamlar. */
    private const FORBIDDEN_SCOPES = [
        'company' => ['global'],                    // müşteri rolü sistem geneli yetki alamaz
        'internal' => ['company', 'organization'],  // iç rol tekil tenant'a bağlanmaz
    ];

    public function run(): void
    {
        $path = database_path('seeders/data/rbac_scope_permission_matrix.csv');

        if (! file_exists($path)) {
            // Fail-closed: CSV yoksa sessizce boş seed atmak yerine dur.
            throw new RuntimeException("RBAC CSV bulunamadı: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("RBAC CSV okunamadı: {$path}");
        }

        try {
            DB::transaction(fn () => $this->import($handle, $path));
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function import($handle, string $path): void
    {
        // PHP 8.4: $escape parametresinin varsayılanı deprecated — açıkça veriyoruz.
        $header = fgetcsv($handle, 0, ',', '"', '\\');

        $expectedHeader = ['module', 'permission', 'role', 'scope', 'requires_jit', 'requires_dual_control', 'notes'];

        if ($header !== $expectedHeader) {
            throw new RuntimeException(
                'RBAC CSV başlığı beklenenden farklı. Beklenen: '.implode(',', $expectedHeader).
                ' Bulunan: '.implode(',', (array) $header)
            );
        }

        $rowCount = 0;
        $lineNo = 1;
        $roleCache = [];
        $permissionCache = [];
        $seenPairs = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lineNo++;

            // Dosya sonundaki boş satırı görmezden gel.
            if ($row === [null] || $row === ['']) {
                continue;
            }

            [$module, $permissionName, $roleName, $scope, $requiresJit, $requiresDualControl, $notes] = array_pad($row, 7, null);

            foreach (['module' => $module, 'permission' => $permissionName, 'role' => $roleName, 'scope' => $scope] as $field => $value) {
                if ($value === null || trim((string) $value) === '') {
                    throw new RuntimeException("CSV satır {$lineNo}: '{$field}' alanı boş.");
                }
            }

            if (! in_array($scope, self::VALID_SCOPES, true)) {
                throw new RuntimeException(
                    "CSV satır {$lineNo}: geçersiz scope '{$scope}'. Geçerli: ".implode('|', self::VALID_SCOPES)
                );
            }

            $jitRaw = strtolower(trim((string) $requiresJit));

            if (! in_array($jitRaw, ['yes', 'no'], true)) {
                // Sessizce false'a düşmesine izin verilmez: JIT kapısı yanlışlıkla
                // açık kalan bir izin, denetimde görünmeyen bir yetki açığıdır.
                throw new RuntimeException("CSV satır {$lineNo}: requires_jit yalnızca 'yes' veya 'no' olabilir, '{$requiresJit}' bulundu.");
            }

            $dualRaw = strtolower(trim((string) $requiresDualControl));

            if (! in_array($dualRaw, ['yes', 'no'], true)) {
                throw new RuntimeException("CSV satır {$lineNo}: requires_dual_control yalnızca 'yes' veya 'no' olabilir, '{$requiresDualControl}' bulundu.");
            }

            // Dual-control, JIT'in üstüne kurulur: ikinci onayın kaydedileceği
            // yer jit_access_grants.approved_by kolonudur. JIT'siz bir izne
            // dual-control koymak, onayın yazılacağı kaydın hiç oluşmaması demektir.
            if ($dualRaw === 'yes' && $jitRaw === 'no') {
                throw new RuntimeException(
                    "CSV satır {$lineNo}: '{$permissionName}' dual-control istiyor ama requires_jit='no'. ".
                    'Dual-control yalnızca JIT gerektiren izinlerde tanımlanabilir.'
                );
            }

            $pairKey = $roleName.'|'.$permissionName;

            if (isset($seenPairs[$pairKey])) {
                throw new RuntimeException(
                    "CSV satır {$lineNo}: ({$roleName}, {$permissionName}) çifti satır {$seenPairs[$pairKey]}'de zaten tanımlı."
                );
            }

            $seenPairs[$pairKey] = $lineNo;

            // --- Role ---
            if (! isset($roleCache[$roleName])) {
                $type = match (true) {
                    in_array($roleName, self::INTERNAL_ROLES, true) => 'internal',
                    in_array($roleName, self::COMPANY_ROLES, true) => 'company',
                    default => throw new RuntimeException("CSV satır {$lineNo}: tanımsız rol adı '{$roleName}'."),
                };

                $role = Role::firstOrNew(['name' => $roleName]);
                // Re-seed'de tip düzeltmesi de uygulanmalı; firstOrCreate bunu kaçırırdı.
                $role->type = $type;
                $role->label ??= ucwords(str_replace('_', ' ', $roleName));
                $role->save();

                $roleCache[$roleName] = $role;
            }

            $role = $roleCache[$roleName];

            if (in_array($scope, self::FORBIDDEN_SCOPES[$role->type], true)) {
                throw new RuntimeException(
                    "CSV satır {$lineNo}: '{$roleName}' ({$role->type}) rolü '{$scope}' kapsamı taşıyamaz — ".
                    "izin '{$permissionName}'."
                );
            }

            // --- Permission ---
            // DİKKAT: modül çakışması kontrolü cache'lenmiş izinler için DE
            // yapılmalı. Yalnızca cache miss'te kontrol etmek, aynı izin adının
            // CSV içinde iki farklı modülle geçmesini sessizce kabul ederdi
            // (ilk satırın modülü kazanır, ikincisi görmezden gelinirdi).
            if (isset($permissionCache[$permissionName])) {
                if ($permissionCache[$permissionName]->module !== $module) {
                    throw new RuntimeException(
                        "CSV satır {$lineNo}: '{$permissionName}' izni CSV içinde hem ".
                        "'{$permissionCache[$permissionName]->module}' hem '{$module}' modülüyle geçiyor."
                    );
                }
            } else {
                $permission = Permission::firstOrNew(['name' => $permissionName]);

                if ($permission->exists && $permission->module !== $module) {
                    throw new RuntimeException(
                        "CSV satır {$lineNo}: '{$permissionName}' izni DB'de '{$permission->module}' ".
                        "modülüne bağlı, CSV '{$module}' diyor."
                    );
                }

                $permission->module = $module;
                $permission->save();

                $permissionCache[$permissionName] = $permission;
            }

            // --- Role <-> Permission (scope + JIT) ---
            RolePermission::updateOrCreate(
                [
                    'role_id' => $role->id,
                    'permission_id' => $permissionCache[$permissionName]->id,
                ],
                [
                    'scope' => $scope,
                    'requires_jit' => $jitRaw === 'yes',
                    'requires_dual_control' => $dualRaw === 'yes',
                    'notes' => ($notes !== '' && $notes !== null) ? $notes : null,
                ]
            );

            $rowCount++;
        }

        if ($rowCount === 0) {
            throw new RuntimeException("RBAC CSV'de hiç veri satırı yok: {$path}");
        }

        // CSV'den silinmiş bir eşleşme DB'de kalmamalı: matris daraltıldığında
        // eski yetki yaşamaya devam ederdi.
        $stale = RolePermission::query()
            ->get()
            ->reject(function (RolePermission $rp) use ($roleCache, $permissionCache, $seenPairs) {
                $roleName = collect($roleCache)->search(fn ($r) => $r->id === $rp->role_id);
                $permName = collect($permissionCache)->search(fn ($p) => $p->id === $rp->permission_id);

                return $roleName !== false && $permName !== false
                    && isset($seenPairs[$roleName.'|'.$permName]);
            });

        $staleCount = $stale->count();
        $stale->each->delete();

        // Seeder::$command Laravel tarafından non-nullable tiplenmiştir: db:seed,
        // Seeder::call() ve testlerdeki $this->seed() hepsi set eder. Seeder'ı
        // `new` ile elle çağırmak desteklenen bir yol değildir.
        $this->command->info(
            "RBAC seed tamamlandı: {$rowCount} satır işlendi, ".count($roleCache).' rol, '.
            count($permissionCache).' izin.'.($staleCount > 0 ? " {$staleCount} eskimiş eşleşme silindi." : '')
        );
    }
}
