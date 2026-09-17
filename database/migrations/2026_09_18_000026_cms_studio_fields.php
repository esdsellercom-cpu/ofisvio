<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMS stüdyo (faz 48): sayfa/yazı editörüne SEO (odak kelime, canonical, robots, OG), GEO/AI arama alanları,
 * şema tercihleri ve SEO skoru. Çalışma taslağı (content_drafts) aynı alanları taşır ki yayındaki içerik
 * taslak akışıyla düzenlenebilsin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $t) {
            $t->string('focus_keyword', 120)->nullable();
            $t->json('related_keywords')->nullable();
            $t->string('canonical_url', 500)->nullable();
            $t->string('robots', 40)->nullable(); // boş = varsayılan (index, follow); noindex bayrağı ayrıca korunur
            $t->string('og_title', 120)->nullable();
            $t->string('og_description', 300)->nullable();
            $t->foreignId('og_media_id')->nullable()->constrained('media')->nullOnDelete();
            $t->json('geo')->nullable(); // summary, topic, entities, questions, faq, answers, related, ai_summary
            $t->json('schema_types')->nullable();
            $t->text('schema_custom')->nullable();
            $t->unsignedSmallInteger('seo_score')->nullable();
        });

        Schema::table('content_drafts', function (Blueprint $t) {
            $t->string('focus_keyword', 120)->nullable();
            $t->json('related_keywords')->nullable();
            $t->string('canonical_url', 500)->nullable();
            $t->string('robots', 40)->nullable();
            $t->string('og_title', 120)->nullable();
            $t->string('og_description', 300)->nullable();
            $t->foreignId('og_media_id')->nullable()->constrained('media')->nullOnDelete();
            $t->json('geo')->nullable();
            $t->json('schema_types')->nullable();
            $t->text('schema_custom')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_drafts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('og_media_id');
            $t->dropColumn(['focus_keyword', 'related_keywords', 'canonical_url', 'robots', 'og_title', 'og_description', 'geo', 'schema_types', 'schema_custom']);
        });
        Schema::table('contents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('og_media_id');
            $t->dropColumn(['focus_keyword', 'related_keywords', 'canonical_url', 'robots', 'og_title', 'og_description', 'geo', 'schema_types', 'schema_custom', 'seo_score']);
        });
    }
};
