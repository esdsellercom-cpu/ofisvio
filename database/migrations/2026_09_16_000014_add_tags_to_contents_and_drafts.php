<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 18 etiketler: içerik ve çalışma taslağında serbest etiket listesi
 * (küçük harf, tekil, en fazla 10). /blog/etiket/{slug} sayfası ve iç
 * bağlantı önerisi bunu kullanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('category');
        });
        Schema::table('content_drafts', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('contents', fn (Blueprint $table) => $table->dropColumn('tags'));
        Schema::table('content_drafts', fn (Blueprint $table) => $table->dropColumn('tags'));
    }
};
