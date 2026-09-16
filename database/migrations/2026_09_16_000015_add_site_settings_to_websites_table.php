<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 29 site genel ayarları: iletişim ve kimlik alanları site başına.
 * NULL = config/ofisvio.php 'brand' varsayılanı (Ofisvio vitrini); müşteri
 * sitesinde NULL = alan gösterilmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('contact_phone', 32)->nullable()->after('legal_name');
            $table->string('contact_email', 190)->nullable()->after('contact_phone');
            $table->string('tagline', 200)->nullable()->after('contact_email');
            $table->string('address', 300)->nullable()->after('tagline');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['contact_phone', 'contact_email', 'tagline', 'address']);
        });
    }
};
