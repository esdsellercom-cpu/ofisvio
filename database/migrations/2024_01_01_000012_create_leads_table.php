<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Siteden gelen teklif ve ön rezervasyon talepleri.
 *
 * KVKK NOTU: Açık rıza kanıtlanabilir olmalıdır. Bu yüzden onay bir boolean
 * değil; ONAY ANI (consented_at), IP ve user-agent ile birlikte saklanır.
 * Rıza verilmeden kayıt oluşturulmaz — doğrulama bunu zorunlu tutar.
 *
 * Bu tablo ticari veri tutar; seeder ile ASLA doldurulmaz (CLAUDE.md
 * "sahte ticari veri yasağı").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            // 'quote' = teklif formu, 'booking' = toplantı odası ön talebi
            $table->string('kind', 24)->default('quote');

            $table->string('name');
            $table->string('email');
            $table->string('phone', 32)->nullable();

            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('solution', 48)->nullable();   // Hazır Ofis / Sanal Ofis / ...
            $table->string('team_size', 16)->nullable();

            // Ön rezervasyon alanları (kind = booking)
            $table->date('requested_date')->nullable();
            $table->string('requested_slot', 8)->nullable();

            $table->text('note')->nullable();

            // KVKK açık rıza kanıtı
            $table->timestamp('consented_at');
            $table->string('consent_ip', 45)->nullable();
            $table->string('consent_user_agent')->nullable();

            $table->string('status', 24)->default('new'); // new / contacted / won / lost
            $table->timestamps();

            $table->index(['kind', 'status', 'created_at']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
