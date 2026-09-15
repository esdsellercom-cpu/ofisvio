<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Şirket aktivasyon state machine'inin denetim izi — V5 bölüm 11.
 *
 * companies.status yalnızca GÜNCEL durumu tutar. "Bu şirket KYC'den ne zaman,
 * kimin onayıyla geçti" sorusunun cevabı burada. Kayıtlar değiştirilmez ve
 * silinmez; bu yüzden updated_at yok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable(); // ilk kayıtta null
            $table->string('to_status', 32);
            // Geçişi yapan kullanıcı. Sistem tarafından otomatik yapılan
            // geçişlerde (ödeme webhook'u vb.) null kalır ve reason bunu yazar.
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_status_transitions');
    }
};
