<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahsilat & belge merkezi (faz 47): tahsilat iptali (silme yok, geçmiş kalır), açıklama/para birimi;
 * belgeler (tahsilat makbuzu, geciken ödeme belgesi) numaralı ve anlık görüntülü; belge şablonları;
 * belge numara sırası (tür + yıl, satır kilidiyle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->string('status', 12)->default('recorded')->after('recorded_by'); // recorded | cancelled
            $t->string('currency', 3)->default('TRY')->after('amount');
            $t->string('description', 200)->nullable()->after('method'); // hizmet / açıklama
            $t->foreignId('cancelled_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $t->dateTime('cancelled_at')->nullable()->after('cancelled_by');
            $t->string('cancel_reason', 200)->nullable()->after('cancelled_at');
        });

        Schema::create('document_templates', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 24)->unique(); // receipt | overdue_notice
            $t->json('fields'); // logo_url, heading, subheading, body, columns, signature, stamp, footer, accent, show_business
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('document_sequences', function (Blueprint $t) {
            $t->string('kind', 24);
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('last')->default(0);
            $t->timestamps();

            $t->primary(['kind', 'year']);
        });

        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 24); // receipt | overdue_notice
            $t->string('number', 24)->unique();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $t->json('data'); // belge anındaki değerler (düzenlenebilir alanlar dahil)
            $t->string('status', 12)->default('issued'); // issued | cancelled
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('cancelled_at')->nullable();
            $t->string('cancel_reason', 200)->nullable();
            $t->timestamps();

            $t->index(['kind', 'created_at']);
            $t->index('invoice_id');
            $t->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('document_templates');
        Schema::table('payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cancelled_by');
            $t->dropColumn(['status', 'currency', 'description', 'cancelled_at', 'cancel_reason']);
        });
    }
};
