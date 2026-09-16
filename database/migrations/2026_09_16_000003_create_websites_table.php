<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website — §66 tenant modeli: Organization -> Website -> SEO/GEO/İçerik.
 *
 * organization_id NULL olan tek kayıt Ofisvio'nun kendi vitrinidir (is_default).
 * Müşteri siteleri (faz 10, çoklu website) aynı tabloya organization_id ile
 * gelir; içerik, SEO ve GEO tabloları website_id üzerinden bağlanır ki tenant
 * sınırı daha ilk tablodan itibaren çizili olsun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable()->unique();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
