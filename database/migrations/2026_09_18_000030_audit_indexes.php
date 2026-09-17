<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Üretim denetimi (faz 52): sık süzülen sütunlarda eksik indeksler (SQLite FK'ya indeks koymaz; MySQL'de de
 * bileşik süzgeçler için gerekli). Veri değişmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->index(['company_id', 'created_at'], 'documents_company_created_idx');
        });
        Schema::table('user_roles', function (Blueprint $table) {
            $table->index(['company_id', 'status'], 'user_roles_company_status_idx');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['invoice_id', 'status'], 'payments_invoice_status_idx');
        });
        Schema::table('contents', function (Blueprint $table) {
            $table->index('parent_id', 'contents_parent_idx');
        });
        Schema::table('extra_charges', function (Blueprint $table) {
            $table->index('invoice_id', 'extra_charges_invoice_idx');
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->index(['status', 'ends_on'], 'contracts_status_ends_idx');
        });
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $t) => $t->dropIndex('documents_company_created_idx'));
        Schema::table('user_roles', fn (Blueprint $t) => $t->dropIndex('user_roles_company_status_idx'));
        Schema::table('payments', fn (Blueprint $t) => $t->dropIndex('payments_invoice_status_idx'));
        Schema::table('contents', fn (Blueprint $t) => $t->dropIndex('contents_parent_idx'));
        Schema::table('extra_charges', fn (Blueprint $t) => $t->dropIndex('extra_charges_invoice_idx'));
        Schema::table('contracts', fn (Blueprint $t) => $t->dropIndex('contracts_status_ends_idx'));
    }
};
