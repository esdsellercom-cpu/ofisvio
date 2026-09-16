<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit: siteden gelen talepler veritabanına yazılıyor ama panelde görünmüyordu
 * (lead.view / lead.assign izinleri vardı, ekranı yoktu). CRM v1: atama + durum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable()->after('assigned_to');
            $table->text('internal_note')->nullable()->after('handled_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['handled_at', 'internal_note']);
        });
    }
};
