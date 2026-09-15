<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KYC belgeleri.
 *
 * GİZLİLİK NOTU: Bu tablo belge İÇERİĞİNİ tutmaz, yalnızca metadata ve
 * depolama yolunu tutar. İçerik private disk'te durur ve yalnızca
 * kyc.view_document (JIT kapılı) ile açılır. storage_path'in kendisi de
 * hassastır — tahmin edilebilir olmaması için rastgele isim kullanılmalı.
 *
 * Fiziksel belgeler (reception tarafından teslim alınan) için ayrı alanlar:
 * bir belge hem dijital hem fiziksel olabilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('type', 48);   // KycDocumentType enum
            $table->string('status', 32)->default('PENDING'); // KycDocumentStatus enum

            $table->string('original_filename');
            $table->string('storage_path');      // RESTRICTED
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64)->nullable(); // bütünlük doğrulaması

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // İnceleme sonucu
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            // Fiziksel belge teslim kaydı (kyc.physical_document.log — reception)
            $table->foreignId('physical_received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('physical_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->timestamp('physical_received_at')->nullable();

            // İmha kaydı (kyc.physical_document.destroy — JIT + dual-control)
            $table->timestamp('physical_destroyed_at')->nullable();
            $table->foreignId('physical_destroyed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
    }
};
