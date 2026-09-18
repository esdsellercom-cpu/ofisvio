<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO & GEO Command Center (faz 60): bulgu durumu. Bulgular her açılışta gerçek veriden yeniden hesaplanır;
 * tabloda yalnız yönetici kararı (yok sayıldı / çözüldü / not) ve son görülme tutulur — bulgu kendisi saklanmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('issue_key', 64); // sha1(kategori|kimlik)
            $table->string('category', 40);
            $table->string('status', 16)->default('open'); // open | ignored | resolved
            $table->string('note', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'issue_key']);
            $table->index(['website_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_issues');
    }
};
