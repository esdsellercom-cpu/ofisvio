<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etkinlikler & topluluk (faz 39d, artifact §9): `events` panelden açılır, yayınlanınca
 * vitrinde listelenir; `event_registrations` vitrin kayıt formu (KVKK rızası + IP).
 * Franchise yönetimi (faz 39e, artifact §14): `franchise_applications` vitrin başvurusu
 * → panel değerlendirme (durum, atama, iç not). Ticari içerik yalnız DB; seed yok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('slug', 140)->unique();
            $table->string('summary', 300)->nullable();
            $table->text('description')->nullable(); // Markdown
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('capacity')->nullable(); // null = sınırsız
            $table->unsignedInteger('price')->default(0); // 0 = ücretsiz (TL)
            $table->boolean('is_published')->default(false);
            $table->boolean('registration_open')->default(true);
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_published', 'starts_at']);
        });

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 32)->nullable();
            $table->string('company_name', 160)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 12)->default('registered'); // registered | cancelled | attended
            $table->dateTime('consented_at');
            $table->string('consent_ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'email']);
            $table->index(['event_id', 'status']);
        });

        Schema::create('franchise_applications', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 32)->nullable();
            $table->string('city', 80);
            $table->string('district', 80)->nullable();
            $table->string('budget', 60)->nullable(); // serbest metin aralık, başvuranın beyanı
            $table->text('experience')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 12)->default('new'); // new | reviewing | approved | rejected
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('internal_note')->nullable();
            $table->dateTime('handled_at')->nullable();
            $table->dateTime('consented_at');
            $table->string('consent_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchise_applications');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('events');
    }
};
