<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Audit F-09 (KVKK saklama): anonimleştirme / dosya imha damgaları — RetentionService yazar. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['leads', 'bookings', 'franchise_applications', 'event_registrations'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('anonymized_at')->nullable()->index();
            });
        }

        Schema::table('kyc_documents', function (Blueprint $t) {
            $t->timestamp('purged_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        foreach (['leads', 'bookings', 'franchise_applications', 'event_registrations'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('anonymized_at');
            });
        }

        Schema::table('kyc_documents', function (Blueprint $t) {
            $t->dropColumn('purged_at');
        });
    }
};
