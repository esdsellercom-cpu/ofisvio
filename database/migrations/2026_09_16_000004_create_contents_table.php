<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMS Core (faz 9): sayfa ve yazılar tek tabloda (kind), yayın akışı state
 * machine ile (ContentStatus). Gövde markdown; HTML render sırasında süzülür.
 *
 * requires_approval: yasal/vergi/KYC içeriği (matris notu "P0B") — APPROVED
 * durumuna uğramadan yayınlanamaz. Diğer içerik inceleme sonrası doğrudan
 * yayınlanabilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);                 // ContentKind: page | post
            $table->string('slug', 190);
            $table->string('title');
            $table->string('excerpt', 500)->nullable();
            $table->longText('body')->nullable();        // markdown
            $table->string('category', 80)->nullable();  // yazı: "Sanal Ofis", "Mevzuat" ...
            $table->unsignedSmallInteger('reading_minutes')->nullable();
            $table->string('status', 24)->default('DRAFT'); // ContentStatus
            $table->boolean('requires_approval')->default(false);
            $table->string('meta_title', 70)->nullable();
            $table->string('meta_description', 160)->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Slug site içinde ve tür içinde tekildir: /blog/{slug} ile /{slug} çakışmaz.
            $table->unique(['website_id', 'kind', 'slug']);
            $table->index(['website_id', 'kind', 'status', 'published_at']);
            $table->index(['status', 'scheduled_for']);
        });

        Schema::create('content_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->string('excerpt', 500)->nullable();
            $table->longText('body')->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');

            $table->unique(['content_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_revisions');
        Schema::dropIfExists('contents');
    }
};
