<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Otomasyon (audit P1-5/7): tek seferlik hatırlatma damgaları; yenilemede kaynak üyelik faturası bağı zaten var. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dateTime('due_reminder_sent_at')->nullable()->after('paid_at');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dateTime('expiring_notice_sent_at')->nullable()->after('auto_renew');
            $table->unsignedSmallInteger('renewal_count')->default(0)->after('expiring_notice_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('due_reminder_sent_at'));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['expiring_notice_sent_at', 'renewal_count']));
    }
};
