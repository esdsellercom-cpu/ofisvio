<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content Engine (faz 18): yayındaki içeriğe bağlı ÇALIŞMA TASLAĞI.
 *
 * Canlı metin yayından düşürülmeden düzenlenir: taslak kopya akıştan geçer
 * (DRAFT -> IN_REVIEW -> (APPROVED ->) yayın = birleştirme) ve yayınlanınca
 * içeriğin üzerine yazılır, yeni revizyon bırakır, silinir. İçerik başına en
 * fazla bir taslak (unique). requires_approval içerikte taslak da onay ister.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug', 190);
            $table->string('excerpt', 500)->nullable();
            $table->longText('body')->nullable();
            $table->string('category', 80)->nullable();
            $table->string('meta_title', 70)->nullable();
            $table->string('meta_description', 160)->nullable();
            $table->boolean('noindex')->default(false);
            $table->string('status', 24)->default('DRAFT'); // ContentStatus: DRAFT | IN_REVIEW | APPROVED
            $table->text('review_note')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_drafts');
    }
};
