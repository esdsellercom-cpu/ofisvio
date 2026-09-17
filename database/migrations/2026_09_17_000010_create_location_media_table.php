<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lokasyon medya yönetimi (faz 3): görsel kategorileri (kapak, galeri, iç/dış mekân,
 * toplantı odası, ofis, coworking, resepsiyon, ortak alan, olanak), sıra, birincil,
 * başlık/altyazı. `media` tablosuna başlık/altyazı + responsive varyantlar
 * (GD ile üretilen 480/960/1600 px kopyalar) + karantina durumu eklenir.
 * locations.cover_media_id denormalize: kartlar ilişki sorgusu olmadan basar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('title', 160)->nullable()->after('alt');
            $table->string('caption', 300)->nullable()->after('title');
            $table->json('variants')->nullable()->after('caption'); // [{w, h, path}]
            $table->string('status', 16)->default('approved')->after('variants'); // karantina zincirini geçen kayıt
        });

        Schema::create('location_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('category', 24);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['location_id', 'media_id', 'category']);
            $table->index(['location_id', 'category', 'sort_order']);
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('cover_media_id')->nullable()->after('geo_meta_description')->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_media_id');
        });
        Schema::dropIfExists('location_media');
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['title', 'caption', 'variants', 'status']);
        });
    }
};
