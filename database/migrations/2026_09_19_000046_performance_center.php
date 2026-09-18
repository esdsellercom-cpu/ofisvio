<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 60f — Performance Command Center: istek örnekleri (TTFB, sorgu sayısı/süresi, bellek, yanıt boyutu, önbellek
 * isabeti), yavaş sorgular (bağlamsız SQL, süre, rota) ve önbellek olayları (geçersizleme kaskadı izi).
 * Örnekleme oranı ve yavaş sorgu eşiği config'ten; tablolar budanır (model:prune).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_samples', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 8);            // site | panel | other
            $table->string('route', 120)->nullable();
            $table->string('path', 160);
            $table->string('method', 8);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms'); // TTFB yaklaşığı: LARAVEL_START → yanıt gönderimi
            $table->unsignedSmallInteger('query_count');
            $table->unsignedInteger('query_ms');
            $table->decimal('memory_mb', 7, 2);
            $table->unsignedInteger('response_bytes')->default(0);
            $table->boolean('cache_hit')->default(false); // 304 ya da uygulama önbelleğinden
            $table->boolean('authenticated')->default(false);
            $table->timestamp('created_at');
            $table->index(['kind', 'created_at']);
            $table->index(['route', 'created_at']);
        });

        Schema::create('slow_queries', function (Blueprint $table) {
            $table->id();
            $table->string('route', 120)->nullable();
            $table->string('sql_hash', 40);
            $table->text('sql');                  // bağlamsız (bindings ? olarak); kişisel veri taşımaz
            $table->unsignedInteger('duration_ms');
            $table->string('connection', 32)->nullable();
            $table->timestamp('created_at');
            $table->index(['sql_hash', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('cache_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger', 60);        // service.updated, content.published, manual.purge…
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('steps');                // kaskad adımları
            $table->unsignedInteger('version_after')->default(0);
            $table->timestamp('created_at');
            $table->index(['website_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_events');
        Schema::dropIfExists('slow_queries');
        Schema::dropIfExists('performance_samples');
    }
};
