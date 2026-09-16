<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 10 menü: sayfa dışı bağlantılar — [{label, url}] listesi, menüde
 * sayfalardan sonra basılır (harici site, telefon, PDF vb.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->json('nav_links')->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('nav_links');
        });
    }
};
