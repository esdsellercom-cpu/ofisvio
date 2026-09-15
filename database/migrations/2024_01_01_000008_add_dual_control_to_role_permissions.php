<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dual-control'ü serbest metinden şemaya taşır.
 *
 * Matriste `kyc.physical_document.destroy` satırlarının notes alanında
 * "dual-control gerektirir" yazıyordu — yani kural TANIMLIYDI ama makine
 * tarafından okunamıyordu, dolayısıyla HİÇBİR YERDE ZORLANMIYORDU.
 * Fiziksel KYC belgesinin imhası tek kişinin kararıyla yapılabiliyordu.
 *
 * Dual-control JIT'in üstüne kurulur: ikinci onay jit_access_grants.approved_by
 * kolonuna yazılır. Bu yüzden requires_dual_control=true olan bir izin
 * zorunlu olarak requires_jit=true olmalıdır (seeder bunu doğrular).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->boolean('requires_dual_control')->default(false)->after('requires_jit');
        });
    }

    public function down(): void
    {
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->dropColumn('requires_dual_control');
        });
    }
};
