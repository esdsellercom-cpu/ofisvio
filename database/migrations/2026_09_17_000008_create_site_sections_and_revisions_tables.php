<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sayfa kurucu (master prompt §21–36): ana sayfa = sıralı bölümler. `site_sections`
 * TASLAKTIR (panelde düzenlenen); vitrin en son YAYINLANMIŞ revizyonun anlık
 * görüntüsünü (`site_revisions.snapshot`) basar. Yayın = taslağın anlık görüntüsünü
 * kaydet + site önbelleğini geçersiz kıl; geri alma = eski anlık görüntüyü taslağa
 * kopyalayıp yeniden yayınla (denetim izli).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('anchor', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->boolean('hide_on_mobile')->default(false);
            $table->boolean('hide_on_desktop')->default(false);
            $table->json('settings')->nullable();
            $table->dateTime('publish_from')->nullable();
            $table->dateTime('publish_until')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['website_id', 'sort_order']);
        });

        Schema::create('site_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('snapshot');
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at');
            $table->timestamps();

            $table->unique(['website_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_revisions');
        Schema::dropIfExists('site_sections');
    }
};
