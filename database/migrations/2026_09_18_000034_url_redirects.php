<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akıllı URL / yönlendirme yönetimi (faz 54):
 *  - url_redirects: site başına eski yol → hedef (301/302/307/308), durum (active | pending öneri | disabled),
 *    kaynak (manual | slug_change | deleted | auto | fallback), benzerlik skoru, isabet sayacı.
 *  - content_url_history: içerik/hizmet/lokasyon URL geçmişi (slug değişimi, silme) + eşleştirme için anlık görüntü.
 *  - not_found_logs: 404 günlüğü (yol, ilk/son görülme, isabet, referer, önerilen hedef + skor, durum).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('url_redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('from_path', 300);
            $table->string('to_path', 500);
            $table->unsignedSmallInteger('code')->default(301);
            $table->string('status', 16)->default('active');
            $table->string('source', 16)->default('manual');
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['website_id', 'from_path']);
            $table->index(['website_id', 'status']);
        });

        Schema::create('content_url_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 16); // content | service | location
            $table->unsignedBigInteger('entity_id');
            $table->string('old_path', 300);
            $table->string('new_path', 300)->nullable(); // null = silindi
            $table->string('reason', 16); // slug_change | parent_change | deleted
            $table->json('snapshot')->nullable(); // başlık, kategori, etiket, özet — silinen kayıt için eşleştirme girdisi
            $table->timestamps();

            $table->index(['website_id', 'old_path']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('not_found_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('path', 300);
            $table->unsignedInteger('hits')->default(1);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->string('referer', 500)->nullable();
            $table->string('suggested_path', 500)->nullable();
            $table->unsignedTinyInteger('suggested_score')->nullable();
            $table->string('status', 16)->default('open'); // open | redirected | ignored
            $table->timestamps();

            $table->unique(['website_id', 'path']);
            $table->index(['website_id', 'status', 'hits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('not_found_logs');
        Schema::dropIfExists('content_url_history');
        Schema::dropIfExists('url_redirects');
    }
};
