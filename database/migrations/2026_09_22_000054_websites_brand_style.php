<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marka renk/tipografi (audit parity): panelden yönetilen tasarım değişkenleri; boş = tema varsayılanı. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->json('brand_style')->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('brand_style');
        });
    }
};
