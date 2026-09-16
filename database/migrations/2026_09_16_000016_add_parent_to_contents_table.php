<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 29 alt sayfa: tek seviyeli ebeveyn (yalnız kind=page). parent_slug
 * bilinçli olarak denormalize: önbellekli listeler ilişki taşımaz, yol
 * (/ebeveyn/sayfa) sorgusuz üretilir; ebeveynin slug'ı değişince servis
 * çocukları günceller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('website_id')->constrained('contents')->nullOnDelete();
            $table->string('parent_slug', 190)->nullable()->after('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('parent_slug');
        });
    }
};
