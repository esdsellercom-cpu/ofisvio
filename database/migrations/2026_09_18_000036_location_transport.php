<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lokasyon ulaşım bilgisi (faz 55): "Metro X durağına 3 dk, otopark var" gibi kısa metin; panelde varlık formunda. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('transport', 500)->nullable()->after('opening_hours');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('transport');
        });
    }
};
