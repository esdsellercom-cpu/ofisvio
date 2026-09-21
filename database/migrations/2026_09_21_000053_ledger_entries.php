<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only muhasebe defteri (audit F-15). Fatura kesimi, tahsilat, tahsilat iptali, fatura iptali ve düzeltme
 * her biri YENİ satırdır; satır asla güncellenmez/silinmez (LedgerEntry modeli yazmayı engeller). Tutar kuruş, imzalı:
 * borç (+) / alacak (−) fatura bakiyesi açısından. `balance_after` fatura üzerindeki kalan bakiye anlık görüntüsü.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24); // invoice_issued | payment_received | payment_reversed | invoice_cancelled | correction
            $table->bigInteger('amount'); // kuruş, imzalı
            $table->string('currency', 3);
            $table->bigInteger('balance_after')->nullable();
            $table->string('memo', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['company_id', 'created_at']);
            $table->index(['invoice_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
