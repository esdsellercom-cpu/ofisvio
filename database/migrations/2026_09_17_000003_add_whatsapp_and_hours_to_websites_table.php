<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parite: WhatsApp numarası ve çalışma saatleri site ayarıdır (kodda sabit yok).
 * whatsapp_number E.164 (+90…); business_hours satır listesi ("Pzt–Cum 08:30–19:00").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('whatsapp_number', 20)->nullable()->after('address');
            $table->json('business_hours')->nullable()->after('whatsapp_number');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_number', 'business_hours']);
        });
    }
};
