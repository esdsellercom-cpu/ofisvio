<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 12-14 cache.settings: site başına uygulama önbelleği TTL'i ve misafir
 * HTTP önbellek süreleri (Cache-Control max-age / s-maxage). NULL = kod
 * varsayılanı (ContentCache::TTL_SECONDS, PublicCacheHeaders sabitleri).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->unsignedInteger('cache_ttl_seconds')->nullable()->after('nav_links');
            $table->unsignedInteger('http_max_age')->nullable()->after('cache_ttl_seconds');
            $table->unsignedInteger('http_s_maxage')->nullable()->after('http_max_age');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['cache_ttl_seconds', 'http_max_age', 'http_s_maxage']);
        });
    }
};
