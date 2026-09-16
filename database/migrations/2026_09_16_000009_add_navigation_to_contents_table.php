<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 10 menü yönetimi: sayfanın menüdeki sırası ve görünürlüğü. Menü,
 * yayındaki + show_in_nav sayfaların nav_order (sonra başlık) sırasıdır;
 * ayrı menü tablosu yok — sayfa dışı bağlantı ihtiyacı çıkınca eklenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->boolean('show_in_nav')->default(true)->after('noindex');
            $table->unsignedSmallInteger('nav_order')->nullable()->after('show_in_nav');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['show_in_nav', 'nav_order']);
        });
    }
};
