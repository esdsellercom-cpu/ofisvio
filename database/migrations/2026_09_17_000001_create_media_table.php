<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medya kütüphanesi (faz 30): vitrin görselleri. Public diskte UUID ad; sha256
 * ile site içinde yinelenen dosya engellenir. Kapak (contents) ve hero (websites)
 * bağları nullOnDelete. contents.cover_url denormalize: önbellekli listeler
 * (ham nitelik) ilişki taşımaz, kart sorgusuz basar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('original_name', 190);
            $table->string('mime_type', 64);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->char('checksum_sha256', 64);
            $table->string('alt', 190)->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'checksum_sha256']);
        });

        Schema::table('contents', function (Blueprint $table) {
            $table->foreignId('cover_media_id')->nullable()->after('parent_slug')->constrained('media')->nullOnDelete();
            $table->string('cover_url')->nullable()->after('cover_media_id');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->foreignId('hero_media_id')->nullable()->after('theme')->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('websites', fn (Blueprint $table) => $table->dropConstrainedForeignId('hero_media_id'));
        Schema::table('contents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_media_id');
            $table->dropColumn('cover_url');
        });
        Schema::dropIfExists('media');
    }
};
