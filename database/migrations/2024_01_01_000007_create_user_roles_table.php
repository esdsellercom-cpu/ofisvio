<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bir kullanıcıya, belirli bir rolü, belirli bir kapsam kaydında (şirket/organizasyon/
// lokasyon) veya global olarak atar. Bkz V5 bölüm 1 — Tenant Context Engine.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            // Yalnızca rolün scope türüne uyan kolon doldurulur, diğerleri null kalır.
            // 'global' scope taşıyan roller (super_admin, system_admin vb.) için üçü de null.
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->cascadeOnDelete();

            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->timestamps();

            // Aynı kullanıcı aynı rolü aynı kapsam kaydında iki kez alamaz.
            $table->unique(['user_id', 'role_id', 'company_id', 'organization_id', 'location_id'], 'user_roles_unique_scope');
        });

        // Just-In-Time erişim talepleri — hassas kaynaklara (KYC belgesi, banka bilgisi vb.)
        // super_admin/system_admin erişiminin gerekçeli, süreli ve audit edilebilir olması için.
        // Bkz V5 bölüm 3.
        Schema::create('jit_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type'); // ör. 'kyc_document'
            $table->unsignedBigInteger('resource_id');
            $table->text('reason');
            $table->dateTime('granted_at');
            $table->dateTime('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(); // ikinci onay gerekiyorsa
            $table->timestamps();

            $table->index(['resource_type', 'resource_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jit_access_grants');
        Schema::dropIfExists('user_roles');
    }
};
