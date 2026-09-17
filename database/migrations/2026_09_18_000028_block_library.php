<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blok kütüphanesi (faz 50): kayıtlı blok kategorisi + global bayrağı; bölüm → kayıtlı blok bağı (global blok
 * değişince bağlı tüm kullanımlar güncellenir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_block_presets', function (Blueprint $table) {
            $table->string('category', 30)->default('ozel')->after('type');
            $table->boolean('is_global')->default(false)->after('category');
        });

        Schema::table('site_sections', function (Blueprint $table) {
            $table->foreignId('preset_id')->nullable()->after('label')->constrained('site_block_presets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preset_id');
        });
        Schema::table('site_block_presets', function (Blueprint $table) {
            $table->dropColumn(['category', 'is_global']);
        });
    }
};
