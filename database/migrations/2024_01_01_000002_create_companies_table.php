<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('legal_name');
            $table->string('tax_number')->nullable(); // RESTRICTED — ileride field-level encryption (bkz V5.1 bölüm 2)
            $table->enum('status', [
                'REGISTERED', 'KYC_PENDING', 'KYC_REVIEW', 'KYC_APPROVED',
                'CONTRACT_PENDING', 'CONTRACT_SIGNED', 'PAYMENT_PENDING',
                'PAYMENT_AUTHORIZED', 'PAYMENT_CONFIRMED', 'ADDRESS_ASSIGNED', 'ACTIVE',
                'SUSPENDED', 'TERMINATION_PENDING', 'TERMINATED',
            ])->default('REGISTERED'); // bkz V5 bölüm 11 — activation state machine
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
        });

        // company_members.role -> V5 bölüm 2'deki enum ile TUTARLI OLMALI,
        // ancak gerçek yetki kaynağı bu kolon değil, roles/permissions/role_permissions/user_roles'tür.
        // Bu kolon yalnızca "şirket içindeki ticari/hukuki unvan" bilgisini taşır (OWNER, LEGAL_REPRESENTATIVE vb.);
        // fiili yetkilendirme user_roles tablosundaki role_id + company_id eşleşmesiyle yapılır.
        Schema::create('company_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('title', [
                'OWNER', 'LEGAL_REPRESENTATIVE', 'ADMIN', 'ACCOUNTANT', 'EMPLOYEE', 'VIEWER',
            ]);
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_members');
        Schema::dropIfExists('companies');
    }
};
