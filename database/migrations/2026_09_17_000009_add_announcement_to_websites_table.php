<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Duyuru şeridi (global bileşen, §30): metin + bağlantı + bitiş; site ayarı, kodda sabit yok. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('announcement_text', 160)->nullable()->after('business_hours');
            $table->string('announcement_href', 300)->nullable()->after('announcement_text');
            $table->dateTime('announcement_until')->nullable()->after('announcement_href');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['announcement_text', 'announcement_href', 'announcement_until']);
        });
    }
};
