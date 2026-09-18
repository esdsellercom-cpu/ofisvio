<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 61b — Entegrasyon merkezi: sağlayıcı başına panelden yazılan ayar (aktif/pasif + secret olmayan alanlar) ve
 * şifrelenmiş secret'lar (encryption-at-rest, APP_KEY). Env her zaman önceliklidir; DB yalnız env boşken devreye girer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->unique();
            $table->boolean('enabled')->nullable(); // null = env'e bırak
            $table->json('config')->nullable();     // secret olmayan alanlar (base_url, model, host, port…)
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('integration_secrets', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('field', 60);
            $table->text('value');                  // Crypt::encryptString
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['provider', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_secrets');
        Schema::dropIfExists('integration_settings');
    }
};
