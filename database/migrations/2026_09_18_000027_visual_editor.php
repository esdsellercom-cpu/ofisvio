<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Görsel site editörü (faz 49): bölüm kilidi/etiketi, kayıtlı blok kütüphanesi, global (header/footer)
 * metin taslağı — yayınlanana kadar vitrine çıkmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_sections', function (Blueprint $table) {
            $table->boolean('locked')->default(false)->after('hide_on_desktop');
            $table->string('label', 80)->nullable()->after('locked');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->json('builder_globals')->nullable()->after('nav_links');
        });

        Schema::create('site_block_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('type', 40);
            $table->json('settings');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_block_presets');
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('builder_globals');
        });
        Schema::table('site_sections', function (Blueprint $table) {
            $table->dropColumn(['locked', 'label']);
        });
    }
};
