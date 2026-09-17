<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Üyelikler & paketler (faz 39b, artifact §6): `plans` = satılan paketler (panelden
 * yönetilir, kodda/seed'de paket yok), `subscriptions` = şirket ↔ paket üyeliği
 * (tenant sınırı company_id). Fiyat üyelikte anlık görüntü olarak saklanır; paket
 * fiyatı sonradan değişse de üyelik tutarı değişmez. Tutarlar TL tam sayı
 * (rezervasyon total_amount ile aynı birim).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 90)->unique();
            $table->string('summary', 300)->nullable();
            $table->text('features')->nullable(); // satır başına bir madde
            $table->unsignedInteger('price')->default(0);
            $table->string('period', 10)->default('monthly'); // monthly | yearly
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete(); // Hizmet kataloğuna bağ
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('status', 12)->default('active'); // active | cancelled | expired
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('price'); // anlık görüntü
            $table->string('period', 10);
            $table->boolean('auto_renew')->default(true);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestamps();

            $table->index(['status', 'ends_on']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
