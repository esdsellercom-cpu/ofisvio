<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coworking operasyon sertleştirme (faz 45):
 *  - rooms/spaces/locations: olanaklar (amenities), kapak görseli (lokasyon galerisinden), bakım durumu.
 *  - bookings: fiyat ayrıştırması (indirim, KDV oranı/tutarı), ödeme durumu (faturadan senkron).
 *  - booking_slots: aynı oda + aynı 15 dk dilimi için VERİTABANI düzeyinde tekil kısıt —
 *    çift rezervasyona karşı son savunma (satır kilidi + çakışma sorgusu üstüne).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tablolar açık yazılır (döngü değil): statik analiz sütunları migration'dan okur.
        Schema::table('rooms', function (Blueprint $t) {
            $t->json('amenities')->nullable();
            $t->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $t->date('maintenance_until')->nullable();
            $t->string('maintenance_note', 200)->nullable();
        });

        Schema::table('spaces', function (Blueprint $t) {
            $t->json('amenities')->nullable();
            $t->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $t->date('maintenance_until')->nullable();
            $t->string('maintenance_note', 200)->nullable();
        });

        Schema::table('locations', function (Blueprint $t) {
            $t->date('maintenance_until')->nullable();
            $t->string('maintenance_note', 200)->nullable();
        });

        Schema::table('bookings', function (Blueprint $t) {
            $t->unsignedBigInteger('discount_amount')->default(0)->after('total_amount'); // kuruş
            $t->string('discount_reason', 200)->nullable()->after('discount_amount');
            $t->unsignedSmallInteger('tax_rate')->default(0)->after('discount_reason'); // % (rezervasyon anında ayardan)
            $t->unsignedBigInteger('tax_amount')->default(0)->after('tax_rate'); // kuruş
            $t->string('payment_status', 12)->default('unpaid')->after('tax_amount'); // unpaid|partial|paid|waived
            $t->dateTime('paid_at')->nullable()->after('payment_status');
        });

        Schema::create('booking_slots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->dateTime('slot_at'); // 15 dakikalık dilim başlangıcı

            $t->unique(['room_id', 'slot_at']);
            $t->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_slots');
        Schema::table('bookings', function (Blueprint $t) {
            $t->dropColumn(['discount_amount', 'discount_reason', 'tax_rate', 'tax_amount', 'payment_status', 'paid_at']);
        });
        Schema::table('locations', function (Blueprint $t) {
            $t->dropColumn(['maintenance_until', 'maintenance_note']);
        });

        Schema::table('spaces', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cover_media_id');
            $t->dropColumn(['amenities', 'maintenance_until', 'maintenance_note']);
        });

        Schema::table('rooms', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cover_media_id');
            $t->dropColumn(['amenities', 'maintenance_until', 'maintenance_note']);
        });
    }
};
