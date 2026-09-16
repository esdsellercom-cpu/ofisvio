<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 10 tema seçimi: websites.theme = config('ofisvio.themes') anahtarı.
 * Tema, ofisvio.css içindeki token setidir (html[data-theme]); derleme yok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('theme', 32)->default('kum')->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
