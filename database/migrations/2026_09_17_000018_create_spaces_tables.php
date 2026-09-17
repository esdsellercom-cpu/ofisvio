<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masa & ofis envanteri (audit P0-2, artifact §3): `spaces` = lokasyondaki sabit masa /
 * esnek masa / özel ofis (saatlik odalar `rooms`'ta kalır); `space_assignments` = alanın
 * bir şirkete (ve isteğe bağlı üyeye/üyeliğe) tahsisi (tenant sınırı company_id).
 * Doluluk buradan hesaplanır; seed yok, ticari veri yalnız panelden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('kind', 12); // desk_fixed | desk_flex | office
            $table->string('name', 60); // "A-12", "Ofis 3"
            $table->string('floor', 30)->nullable();
            $table->string('zone', 60)->nullable();
            $table->unsignedSmallInteger('capacity')->default(1); // ofis: kişi; esnek masa alanı: eşzamanlı üye
            $table->unsignedBigInteger('monthly_price')->default(0); // kuruş
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('notes', 300)->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'name']);
            $table->index(['location_id', 'kind', 'is_active']);
        });

        Schema::create('space_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // üye (masa kime)
            $table->string('status', 8)->default('active'); // active | ended
            $table->date('starts_on');
            $table->date('ends_on')->nullable(); // null = süresiz
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            $table->index(['space_id', 'status']);
            $table->index(['company_id', 'status']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('space_kind', 12)->nullable()->after('service_id'); // paket → alan türü bağı
        });
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('space_kind'));
        Schema::dropIfExists('space_assignments');
        Schema::dropIfExists('spaces');
    }
};
