<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Hangi şapkayla girdi" sorusunun cevabı: üyelik yolu mu, personel yolu mu?
 * TenantContext iki giriş yolu tanımlar; log bunu ayırt etmezse personelin
 * müşteri verisine girişleri üyelik girişlerinin arasında kaybolur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('context_switch_logs', function (Blueprint $table) {
            $table->string('entry_path', 16)->default('membership')->after('to_organization_id');
            $table->index(['user_id', 'switched_at']);
        });
    }

    public function down(): void
    {
        Schema::table('context_switch_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'switched_at']);
            $table->dropColumn('entry_path');
        });
    }
};
