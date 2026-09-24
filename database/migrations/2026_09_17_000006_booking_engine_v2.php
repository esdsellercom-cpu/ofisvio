<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Engine v2 (master prompt §4–11): talep akışı, durum makinesi, onay,
 * müşteri alanları (siteden gelen talep — henüz şirket hesabı olmayabilir),
 * referans numarası, süre dolumu, durum geçmişi.
 *
 * bookings.company_id NULL olabilir: vitrin talebi organizasyonsuzdur; tenant
 * scope'u (company_id IN org şirketleri) onu müşteri panelinde göstermez, personel
 * withoutTenantScope ile görür. Durum değerleri BookingStatus enum'una geçer
 * (v1 'confirmed'/'cancelled' → 'CONFIRMED'/'CANCELLED').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->foreignId('booked_by')->nullable()->change();
            $table->uuid('uuid')->nullable()->after('id');
            $table->string('reference', 24)->nullable()->after('uuid');
            $table->string('customer_name', 120)->nullable()->after('booked_by');
            $table->string('customer_email', 190)->nullable()->after('customer_name');
            $table->string('customer_phone', 32)->nullable()->after('customer_email');
            $table->string('company_name', 160)->nullable()->after('customer_phone');
            $table->unsignedSmallInteger('participant_count')->default(1)->after('ends_at');
            $table->string('source', 16)->default('panel')->after('status'); // site|panel|desk
            $table->string('locale', 5)->default('tr')->after('source');
            $table->boolean('approval_required')->default(true)->after('locale');
            $table->foreignId('approved_by')->nullable()->after('approval_required')->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable()->after('approved_by');
            $table->string('rejected_reason', 200)->nullable()->after('approved_at');
            $table->string('internal_note', 500)->nullable()->after('note');
            $table->dateTime('expires_at')->nullable()->after('rejected_reason');
            $table->dateTime('checked_in_at')->nullable()->after('expires_at');
            $table->dateTime('completed_at')->nullable()->after('checked_in_at');
            $table->timestamp('consented_at')->nullable()->after('completed_at');
            $table->string('consent_ip', 45)->nullable()->after('consented_at');

            $table->unique('uuid');
            $table->unique('reference');
            $table->index(['status', 'starts_at']);
        });

        Schema::create('booking_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 300)->nullable();
            $table->dateTime('created_at');

            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_history');
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropUnique(['reference']);
            $table->dropIndex(['status', 'starts_at']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['uuid', 'reference', 'customer_name', 'customer_email', 'customer_phone', 'company_name', 'participant_count', 'source', 'locale', 'approval_required', 'approved_at', 'rejected_reason', 'internal_note', 'expires_at', 'checked_in_at', 'completed_at', 'consented_at', 'consent_ip']);
        });
    }
};
