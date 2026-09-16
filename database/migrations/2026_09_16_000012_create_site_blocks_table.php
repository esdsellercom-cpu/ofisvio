<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 10: vitrin blokları. Kayıt = config/ofisvio.php varsayılanını ezer;
 * kayıt yoksa config geçerli. Blok başına tek satır (website_id + key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->json('data');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['website_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_blocks');
    }
};
