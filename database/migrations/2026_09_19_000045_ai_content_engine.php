<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 60e — AI Content Engine: prompt kayıt defteri (sürümlü), iş kayıtları (aşama, sağlayıcı/model/prompt sürümü,
 * token/maliyet, geçmiş, insan onayı) ve içerik yenileme adayları. AI yayındaki içeriğe dokunmaz: sonuç ya yeni
 * taslak Content ya da yayındaki içeriğin çalışma taslağıdır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40);          // draft | fact_check | refresh | brief
            $table->unsignedSmallInteger('version');
            $table->string('name', 120);
            $table->text('system');
            $table->text('template');           // {topic} {brief} {brand} {services} {locations} {keywords} {body} yer tutucuları
            $table->string('model', 80)->nullable(); // boş = sağlayıcı varsayılanı
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['key', 'version']);
        });

        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12)->default('article'); // article | refresh
            $table->string('stage', 16)->default('brief');  // brief|draft|fact_check|seo|geo|duplicate|review|approval|schedule|publish|done|rejected
            $table->string('topic', 200);
            $table->json('brief')->nullable();
            $table->json('draft')->nullable();   // title, excerpt, body, meta_title, meta_description, faq
            $table->json('checks')->nullable();  // fact, seo, geo, duplicate
            $table->json('history')->nullable(); // [{stage, at, by, note}]
            $table->string('provider', 32)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('prompt_key', 40)->nullable();
            $table->unsignedSmallInteger('prompt_version')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 10, 4)->nullable(); // yalnız fiyat env'de tanımlıysa
            $table->string('cost_currency', 3)->nullable();
            $table->foreignId('source_content_id')->nullable()->constrained('contents')->nullOnDelete(); // refresh
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();        // sonuç
            $table->timestamp('scheduled_for')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['website_id', 'stage']);
        });

        Schema::create('content_refresh_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->json('reasons');
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('status', 12)->default('open'); // open | planned | done | ignored
            $table->foreignId('ai_job_id')->nullable()->constrained('ai_jobs')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('detected_at');
            $table->timestamps();
            $table->unique(['website_id', 'content_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_refresh_candidates');
        Schema::dropIfExists('ai_jobs');
        Schema::dropIfExists('ai_prompts');
    }
};
