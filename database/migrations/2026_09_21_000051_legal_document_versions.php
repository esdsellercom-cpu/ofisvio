<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit F-07 (KVKK): yasal metin sürümleri ve rıza kayıtları. Sürüm satırı değişmez (append-only): yasal sayfa
 * (KVKK / gizlilik / çerez / koşullar) footer'da seçildiğinde ya da içeriği yeniden yayınlandığında gövde özeti
 * değiştiyse yeni sürüm açılır. Vitrin formundaki her rıza, o anki sürüme bağlanır — "hangi metne rıza verildi"
 * kanıtlanabilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // kvkk | privacy | cookies | terms
            $table->unsignedInteger('version');
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->string('title', 200);
            $table->string('content_hash', 64); // sha256(gövde)
            $table->dateTime('published_at');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 200)->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'kind', 'version']);
            $table->index(['website_id', 'kind', 'published_at']);
        });

        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_document_version_id')->nullable()->constrained('legal_document_versions')->nullOnDelete();
            $table->string('kind', 20); // rıza verilen metin türü
            $table->string('subject_type', 40); // lead | booking | franchise_application | event_registration
            $table->unsignedBigInteger('subject_id');
            $table->string('content_hash', 64)->nullable(); // sürüm yoksa null (doctor üretimde uyarır)
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('accepted_at');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('legal_document_versions');
    }
};
