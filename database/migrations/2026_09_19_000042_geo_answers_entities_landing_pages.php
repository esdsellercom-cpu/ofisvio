<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 60b — GEO Answer Engine (hizmet başına yapılandırılmış cevaplar), varlık ilişkileri (Article→Service/Location,
 * Topic→Entity) ve programatik SEO sayfaları (hizmet × şehir; benzersiz içerik + zayıf/kopya denetimi zorunlu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->json('answers')->nullable()->after('description'); // what/who/how/where/pricing/requirements/documents/process/advantages/limitations/faq/related_*
        });

        Schema::create('entity_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('from_type', 20); // content | service | location | topic
            $table->string('from_id', 80);   // id ya da topic anahtarı (slug)
            $table->string('to_type', 20);
            $table->string('to_id', 80);
            $table->string('relation', 30)->default('related'); // related | about | serves | mentions
            $table->timestamps();
            $table->unique(['website_id', 'from_type', 'from_id', 'to_type', 'to_id', 'relation'], 'entity_relations_unique');
            $table->index(['website_id', 'to_type', 'to_id']);
        });

        Schema::create('seo_landing_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('city_slug', 80);
            $table->string('title', 120);
            $table->string('meta_description', 200)->nullable();
            $table->text('intro');            // benzersiz içerik (zorunlu, ≥ 400 karakter)
            $table->text('body')->nullable(); // Markdown
            $table->json('faq')->nullable();
            $table->boolean('is_indexable')->default(true);
            $table->string('status', 12)->default('draft'); // draft | published
            $table->timestamp('published_at')->nullable();
            $table->json('quality')->nullable(); // son denetim: {ok, score, issues[], checked_at}
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['website_id', 'service_id', 'location_id']);
            $table->index(['website_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_landing_pages');
        Schema::dropIfExists('entity_relations');
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('answers'));
    }
};
