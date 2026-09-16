<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GEO Engine + Entity (faz 16-17) — lokasyon varlık alanları ve website
 * düzeyinde Organization kimliği (sameAs).
 *
 * Koordinat/telefon/saat LocalBusiness şemasının çekirdeğidir; eksikse
 * geo.audit bulgu verir, şema alanı basılmaz (uydurma değer yazılmaz).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('address_line');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('district', 64)->nullable()->after('longitude');      // "Şişli"
            $table->string('postal_code', 16)->nullable()->after('district');
            $table->string('phone', 32)->nullable()->after('postal_code');
            $table->json('opening_hours')->nullable()->after('phone');            // ["Mo-Fr 08:30-19:00", "Sa 09:00-14:00"]
            $table->text('geo_description')->nullable()->after('opening_hours');  // markdown, lokasyon sayfası gövdesi
            $table->string('geo_meta_description', 160)->nullable()->after('geo_description');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->json('same_as')->nullable()->after('seo_locale');  // Organization.sameAs: sosyal/kurumsal profiller
            $table->string('legal_name')->nullable()->after('same_as');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['same_as', 'legal_name']);
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'district', 'postal_code', 'phone', 'opening_hours', 'geo_description', 'geo_meta_description']);
        });
    }
};
