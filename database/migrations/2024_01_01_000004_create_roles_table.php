<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kaynak: ofisvio-rbac-scope-permission-matrix.csv
// 'internal'  -> Ofisvio personeli (super_admin, system_admin, finance_admin,
//                operations_admin, reception, location_manager)
// 'company'   -> müşteri tarafı şirket rolleri (owner, legal_representative,
//                company_admin, accountant, employee, viewer)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // ör. 'owner', 'finance_admin'
            $table->enum('type', ['internal', 'company']);
            $table->string('label')->nullable(); // ekranda gösterilecek ad
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
