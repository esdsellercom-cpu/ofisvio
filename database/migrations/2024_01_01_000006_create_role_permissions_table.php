<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Bir rol, bir izni, hangi scope türünde ve JIT gerektirip gerektirmeden taşır"
// bkz. V5 bölüm 2 (RBAC+Scope matrisi) ve V5 bölüm 3 (Super Admin != Root / JIT).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();

            // Bu izin bu rol için hangi kapsamda geçerli.
            // 'company'      -> yalnızca user_roles.company_id ile eşleşen şirket
            // 'organization' -> yalnızca user_roles.organization_id ile eşleşen organizasyon
            // 'location'     -> yalnızca user_roles.location_id ile eşleşen lokasyon
            // 'global'       -> tüm sistem (yalnızca internal roller)
            $table->enum('scope', ['company', 'organization', 'location', 'global']);

            // true ise: bu izin kullanılmadan önce Just-In-Time authorization akışı
            // zorunlu (Reason + time-limited grant + audit). Bkz V5 bölüm 3.
            $table->boolean('requires_jit')->default(false);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
