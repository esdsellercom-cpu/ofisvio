<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kimlik çekirdeği (audit: giriş geçmişi, hesap durumu). `login_events` Fortify/Auth olaylarından
 * yazılır (başarılı giriş, başarısız deneme, çıkış, kilitlenme, şifre sıfırlama, 2FA); 180 gün sonra
 * budanır. `users.status` askıya alınan hesabın girişini ve açık oturumlarını keser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email', 190)->nullable(); // başarısız denemede kullanıcı çözülemeyebilir
            $table->string('event', 24); // login | failed | logout | lockout | password_reset | two_factor
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['email', 'created_at']);
            $table->index('created_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 12)->default('active')->after('ui_theme'); // active | suspended
            $table->dateTime('suspended_at')->nullable()->after('status');
            $table->string('suspended_reason', 300)->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['status', 'suspended_at', 'suspended_reason']));
        Schema::dropIfExists('login_events');
    }
};
