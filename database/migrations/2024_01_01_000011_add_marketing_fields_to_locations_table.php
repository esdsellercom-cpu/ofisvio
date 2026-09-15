<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * locations tablosuna vitrin alanları.
 *
 * TASARIM KARARI: Pazarlama sitesi için AYRI bir tablo açmıyoruz. Lokasyon
 * zaten operasyonel bir varlık (resepsiyon rolü location kapsamlıdır, ziyaretçi
 * ve kargo kayıtları buraya bağlanır). Vitrin alanlarını ayrı tabloya koymak
 * iki kaynak yaratır ve "sitede görünen şube ile sistemdeki şube" birbirinden
 * kayar.
 *
 * is_published, is_active'ten AYRIDIR: bir lokasyon operasyonda aktif olabilir
 * ama henüz siteye açılmamış olabilir (açılış öncesi kurulum).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('city', 64)->nullable()->after('slug');            // "İstanbul"
            $table->string('region', 64)->nullable()->after('city');          // "İstanbul Avrupa"
            $table->string('address_line')->nullable()->after('region');
            $table->string('badge')->nullable()->after('address_line');       // "amiral kat · 14. kat terası"
            $table->json('tags')->nullable()->after('badge');                 // ["Hazır Ofis","Coworking"]
            $table->string('price_from', 48)->nullable()->after('tags');      // "Masa ₺4.900/ay"
            $table->boolean('is_published')->default(false)->after('is_active');
            $table->unsignedInteger('sort_order')->default(0)->after('is_published');

            $table->index(['is_published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex(['is_published', 'sort_order']);
            $table->dropColumn([
                'city', 'region', 'address_line', 'badge', 'tags',
                'price_from', 'is_published', 'sort_order',
            ]);
        });
    }
};
