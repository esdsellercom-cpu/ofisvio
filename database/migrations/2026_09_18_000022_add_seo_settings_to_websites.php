<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO & GEO gelişmiş ayarları (faz 44): site başına JSON. Tanımlar App\Seo\SeoSettingsRegistry'de;
 * yalın sütunlar (seo_title_suffix, seo_default_description, robots_index, seo_locale) olduğu gibi kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->json('seo_settings')->nullable()->after('seo_locale');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('seo_settings');
        });
    }
};
