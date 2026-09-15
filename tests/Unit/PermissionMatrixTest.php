<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\RolePermission;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Matrisin kendi bütünlüğü. Bu testler koddan çok CSV'yi korur:
 * matris elle düzenlenen bir dosyadır ve yanlış bir satır sessizce
 * yetki açar.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function csv_deki_tum_satirlar_veritabanina_yazilir(): void
    {
        $csv = database_path('seeders/data/rbac_scope_permission_matrix.csv');
        $dataRows = count(array_filter(file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))) - 1;

        $this->assertSame($dataRows, RolePermission::count());
    }

    #[Test]
    public function seeder_idempotenttir(): void
    {
        $before = RolePermission::count();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame($before, RolePermission::count());
    }

    #[Test]
    public function musteri_rolleri_global_kapsam_tasimaz(): void
    {
        $violations = RolePermission::query()
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('roles.type', 'company')
            ->where('role_permissions.scope', 'global')
            ->count();

        $this->assertSame(0, $violations, 'Müşteri rolü sistem geneli yetki taşıyamaz.');
    }

    #[Test]
    public function ic_roller_tekil_tenanta_baglanmaz(): void
    {
        $violations = RolePermission::query()
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('roles.type', 'internal')
            ->whereIn('role_permissions.scope', ['company', 'organization'])
            ->count();

        $this->assertSame(0, $violations);
    }

    #[Test]
    public function her_rolun_tipi_tanimlidir(): void
    {
        $this->assertSame(0, Role::whereNotIn('type', ['internal', 'company'])->count());
    }

    #[Test]
    public function hassas_izinler_jit_gerektirir(): void
    {
        // Bu liste bilinçli olarak testte sabittir: CSV'de biri yanlışlıkla
        // requires_jit'i 'no'ya çevirirse test kırılmalı.
        $mustRequireJit = [
            'kyc.view_document', 'kyc.download', 'kyc.physical_document.destroy',
            'invoice.refund', 'payment.refund', 'ledger.correction_entry',
            'break_glass.activate', 'phpmyadmin.access', 'secret.manage', 'role.manage',
            'data_export.approve', 'contract.cancel',
        ];

        foreach ($mustRequireJit as $permission) {
            $withoutJit = RolePermission::query()
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('permissions.name', $permission)
                ->where('role_permissions.requires_jit', false)
                ->count();

            $this->assertSame(0, $withoutJit, "{$permission} JIT gerektirmeli ama gerektirmeyen bir kayıt var.");
        }
    }

    #[Test]
    public function dual_control_yalnizca_jit_gerektiren_izinlerde_tanimlidir(): void
    {
        // Dual-control, JIT'in üstüne kurulur: ikinci onay
        // jit_access_grants.approved_by kolonuna yazılır. JIT'siz bir izne
        // dual-control koymak, onayın yazılacağı kaydın hiç oluşmaması demektir.
        $violations = RolePermission::where('requires_dual_control', true)
            ->where('requires_jit', false)
            ->count();

        $this->assertSame(0, $violations);
    }

    #[Test]
    public function fiziksel_belge_imhasi_dual_control_gerektirir(): void
    {
        // Matriste notes alanında "dual-control gerektirir" yazıyordu ama
        // makine tarafından okunamadığı için hiçbir yerde zorlanmıyordu.
        $rows = RolePermission::query()
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.name', 'kyc.physical_document.destroy')
            ->get();

        $this->assertGreaterThan(0, $rows->count());

        foreach ($rows as $row) {
            $this->assertTrue((bool) $row->requires_dual_control, 'Fiziksel belge imhası tek kişiyle yapılamamalı.');
            $this->assertTrue((bool) $row->requires_jit);
        }
    }

    #[Test]
    public function super_admin_root_degildir(): void
    {
        // V5 bölüm 3: super_admin'in taşıdığı izinlerin önemli bir kısmı
        // JIT kapılı olmalı; hepsi serbestse "root" demektir.
        $superAdminId = Role::where('name', 'super_admin')->value('id');
        $total = RolePermission::where('role_id', $superAdminId)->count();
        $jitGated = RolePermission::where('role_id', $superAdminId)->where('requires_jit', true)->count();

        $this->assertGreaterThan(0, $total);
        $this->assertGreaterThanOrEqual(
            10,
            $jitGated,
            'super_admin izinlerinin çoğu JIT kapılı olmalı — aksi halde Super Admin = Root.'
        );
    }
}
