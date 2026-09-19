<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook merkezi (faz 61c): giden uç noktalar (imza secret'ı şifreli) ve teslimat logu (deneme sayısı, durum,
 * yanıt süresi, hata, sonraki deneme). Payload'ın saklanan kopyası kişisel veri taşımaz (WebhookPayload::redact).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('url', 500);
            $table->text('secret'); // Crypt ile şifreli (encryption-at-rest)
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('retry_max')->default(3);
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->text('description')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('event', 80);
            $table->string('delivery_id', 40)->unique(); // X-Ofisvio-Delivery başlığı
            $table->json('payload'); // kişisel veri maskeli kopya (ekran)
            $table->text('body'); // gönderilen gövde, Crypt ile şifreli (yalnız tekrar gönderim okur)
            $table->string('status', 16)->default('pending'); // pending | success | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->boolean('manual')->default(false); // panelden test / tekrar gönderim
            $table->timestamps();

            $table->index(['endpoint_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
