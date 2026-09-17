<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envanter yönetimi yeniden tasarımı (faz 46): masa/ofis ve odalara envanter kodu; demirbaş tablosu
 * (lokasyona bağlı, isteğe bağlı bir alana yerleşik, tahsisle birlikte üyeye verilebilir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $t) {
            $t->string('code', 40)->nullable()->after('name');
        });

        Schema::table('rooms', function (Blueprint $t) {
            $t->string('code', 40)->nullable()->after('name');
        });

        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->foreignId('space_id')->nullable()->constrained()->nullOnDelete(); // yerleşik olduğu alan
            $t->foreignId('space_assignment_id')->nullable()->constrained('space_assignments')->nullOnDelete(); // birlikte tahsis edildiği kayıt
            $t->string('name', 120);
            $t->string('code', 40)->nullable();
            $t->string('category', 20)->default('furniture'); // furniture | electronics | it | decoration | other
            $t->string('serial', 80)->nullable();
            $t->string('status', 12)->default('available'); // available | assigned | maintenance | retired
            $t->string('notes', 300)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique('code');
            $t->index(['location_id', 'status']);
            $t->index('space_assignment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
        Schema::table('rooms', function (Blueprint $t) {
            $t->dropColumn('code');
        });
        Schema::table('spaces', function (Blueprint $t) {
            $t->dropColumn('code');
        });
    }
};
