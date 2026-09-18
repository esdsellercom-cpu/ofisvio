<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 61a — Global header / footer yapılandırması (site başına JSON) ve sürüm geçmişi (geri alma).
 * Taslak `websites.builder_globals.header|footer` içinde (görsel editör yayınıyla birlikte canlıya geçer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->json('header_config')->nullable()->after('builder_globals');
            $table->json('footer_config')->nullable()->after('header_config');
        });

        Schema::create('site_chrome_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('area', 12); // header | footer
            $table->unsignedInteger('number');
            $table->json('config');
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['website_id', 'area', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_chrome_versions');
        Schema::table('websites', fn (Blueprint $table) => $table->dropColumn(['header_config', 'footer_config']));
    }
};
