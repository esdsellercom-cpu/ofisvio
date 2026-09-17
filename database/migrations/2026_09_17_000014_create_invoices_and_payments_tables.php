<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ödemeler & faturalandırma (faz 39c, artifact §7–8): `invoices` şirkete kesilen
 * fatura (tenant sınırı company_id; üyelik ya da rezervasyona bağlanabilir),
 * `payments` faturaya kaydedilen tahsilat. Tutarlar TL tam sayı. Numara yayınlama
 * anında verilir (taslakta yok). Durum yalnız InvoiceService yazar:
 * draft → issued → paid · issued → overdue → paid · draft/issued/overdue → cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('status', 12)->default('draft'); // draft | issued | overdue | paid | cancelled
            $table->string('description', 300);
            $table->unsignedInteger('subtotal');
            $table->unsignedTinyInteger('tax_rate')->default(0);
            $table->unsignedInteger('tax_amount')->default(0);
            $table->unsignedInteger('total');
            $table->unsignedInteger('paid_amount')->default(0);
            $table->string('currency', 3)->default('TRY');
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestamps();

            $table->index(['status', 'due_on']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('method', 12); // transfer | card | cash | other
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'paid_on']);
            $table->index('paid_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
    }
};
