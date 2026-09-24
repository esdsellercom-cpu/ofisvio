<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bkz: ofisvio-v4-security-architecture-hardening-spec.md bölüm 2
// organizations -> companies -> company_members modeli
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        // Kullanıcı <-> Organization N:N üyeliği (bkz. V5 bölüm 1 — Tenant Context Engine).
        // Rol taşımaz, yalnızca "bu kullanıcı bu organizasyona üye mi" bilgisini tutar.
        // Şirket bazlı roller ayrıca user_roles tablosunda tanımlanır.
        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });

        // Aktif tenant context değişikliklerinin audit'i (V5 bölüm 1.2).
        Schema::create('context_switch_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('to_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('switched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('context_switch_logs');
        Schema::dropIfExists('organization_members');
        Schema::dropIfExists('organizations');
    }
};
