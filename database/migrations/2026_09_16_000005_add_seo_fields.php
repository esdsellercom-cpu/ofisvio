<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Engine (faz 15) — website başına ayarlar, içerik başına indeks bayrağı.
 *
 * robots_index=false tüm siteyi arama motorlarından çıkarır: robots.txt
 * "Disallow: /" olur, her sayfa noindex alır, sitemap boşalır. Bu yüzden
 * seo.settings matriste JIT ister.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('seo_title_suffix', 80)->nullable()->after('is_default');   // " — Ofisvio"
            $table->string('seo_default_description', 160)->nullable()->after('seo_title_suffix');
            $table->boolean('robots_index')->default(true)->after('seo_default_description');
            $table->string('seo_locale', 10)->default('tr_TR')->after('robots_index');
        });

        Schema::table('contents', function (Blueprint $table) {
            $table->boolean('noindex')->default(false)->after('meta_description');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('noindex');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['seo_title_suffix', 'seo_default_description', 'robots_index', 'seo_locale']);
        });
    }
};
