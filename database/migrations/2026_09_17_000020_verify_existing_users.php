<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E-posta doğrulama zorunluluğu (audit S-4): var olan hesaplar operatör/komut satırı ile açıldı ve
 * kullanılıyor; kilitlenmemeleri için doğrulanmış sayılır. Yeni davetliler şifre belirlerken doğrulanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Geri alınamaz (hangi hesapların doğrulanmamış olduğu bilgisi yok); bilinçli.
    }
};
