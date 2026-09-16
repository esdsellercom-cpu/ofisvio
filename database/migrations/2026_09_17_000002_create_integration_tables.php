<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 5 entegrasyon altyapısı. integration_logs: giden istek kaydı (secret/gövde
 * YOK). webhook_events: gelen, imzası doğrulanmış olaylar; (provider, event_id)
 * tekil = replay/tekrar teslim idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('method', 8);
            $table->string('path', 190);
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->boolean('ok')->default(false);
            $table->string('error', 200)->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('event_id', 190);
            $table->json('payload');
            $table->string('status', 16)->default('received'); // received | processed | failed
            $table->string('source_ip', 45)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index(['provider', 'status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('integration_logs');
    }
};
