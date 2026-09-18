<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 60d — Search Console / Analytics günlük özetleri (yalnız bağlı sağlayıcıdan gelen gerçek satırlar),
 * senkron durumu (mülk, doğrulama, sitemap listesi, son hata) ve Core Web Vitals örnekleri (PageSpeed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_performance_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('dimension', 12); // total | query | page | country | device
            $table->string('key', 500)->default('');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->default(0);
            $table->decimal('position', 7, 2)->default(0);
            $table->timestamps();
            $table->index(['website_id', 'dimension', 'date']);
        });

        Schema::create('analytics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('dimension', 16); // total | landing_page | device | source
            $table->string('key', 500)->default('');
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('users')->default(0);
            $table->unsignedInteger('engaged_sessions')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('engagement_rate', 6, 4)->default(0);
            $table->timestamps();
            $table->index(['website_id', 'dimension', 'date']);
        });

        Schema::create('integration_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->json('meta')->nullable(); // mülkler, doğrulama, sitemap durumu…
            $table->timestamps();
            $table->unique(['website_id', 'provider']);
        });

        Schema::create('web_vitals_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('path', 300);
            $table->string('strategy', 8); // mobile | desktop
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('lab')->nullable();
            $table->json('field')->nullable();
            $table->timestamp('measured_at');
            $table->timestamps();
            $table->index(['website_id', 'path', 'strategy', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_vitals_samples');
        Schema::dropIfExists('integration_sync_states');
        Schema::dropIfExists('analytics_daily');
        Schema::dropIfExists('search_performance_daily');
    }
};
