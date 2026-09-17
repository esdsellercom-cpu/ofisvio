<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking v1: odalar lokasyona bağlı Ofisvio varlığıdır (tenant değil);
 * rezervasyon müşteri şirketine aittir (company_id = tenant sınırı) ve
 * resepsiyon masası için location_id denormalize edilir.
 *
 * Çakışma kontrolü uygulamada (BookingService::book) işlem + kilit ile yapılır;
 * aralık çakışması tek bir unique index ile ifade edilemez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('kind', 20)->default('meeting'); // meeting | event | focus
            $table->unsignedSmallInteger('capacity')->default(4);
            $table->unsignedInteger('hourly_rate')->default(0); // TL, KDV hariç, tam sayı
            $table->string('open_from', 5)->default('09:00');
            $table->string('open_until', 5)->default('18:00');
            $table->unsignedSmallInteger('slot_minutes')->default(60);
            $table->unsignedSmallInteger('max_hours')->default(8);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('description', 300)->nullable();
            $table->timestamps();

            $table->index(['location_id', 'is_active', 'sort_order']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booked_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20); // confirmed | cancelled
            $table->unsignedInteger('total_amount')->default(0); // TL, KDV hariç
            $table->string('note', 300)->nullable();
            $table->boolean('overridden')->default(false); // booking.admin_override ile kural dışı açıldı
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 200)->nullable();
            $table->timestamps();

            $table->index(['room_id', 'starts_at', 'ends_at']);
            $table->index(['company_id', 'starts_at']);
            $table->index(['location_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('rooms');
    }
};
