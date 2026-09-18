<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keyword Intelligence (faz 60c): anahtar kelime → sayfa/hizmet/lokasyon eşlemesi, arama niyeti, konu kümesi.
 * Hacim/sıralama verisi burada tutulmaz — o veri yalnız bağlı Search Console'dan gelir (sahte rakam yok).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 120);
            $table->string('normalized', 120); // küçük harf, tekil boşluk — kanibalizasyon karşılaştırması
            $table->string('role', 12)->default('primary'); // primary | secondary
            $table->string('intent', 16)->default('informational'); // informational | commercial | transactional | navigational | local
            $table->string('cluster', 80)->nullable(); // konu kümesi (slug)
            $table->string('target_type', 16)->nullable(); // content | service | location | landing | path
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_path', 300)->nullable(); // çözülmüş yol (görüntü/karşılaştırma)
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['website_id', 'normalized']);
            $table->index(['website_id', 'cluster']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_keywords');
    }
};
