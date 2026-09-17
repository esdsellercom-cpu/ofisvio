<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bildirim merkezi (master prompt §16–19): alıcılar (kanal + adres + grup, DB'de —
 * kodda telefon yok), kurallar (olay × kanal × grup), şablonlar (DB'de; kod yalnız
 * teknik varsayılan), gönderim günlüğü (secret'siz), uygulama içi bildirimler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('channel', 16); // whatsapp|sms|email|in_app
            $table->string('address', 190)->nullable(); // telefon (E.164) / e-posta; in_app -> user_id
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group', 32); // booking_managers|super_admin|location_managers|finance|crm|compliance|custom
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group', 'channel', 'is_active']);
        });

        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('event', 48);
            $table->string('channel', 16);
            $table->string('recipient_group', 32);
            $table->boolean('enabled')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event', 'channel', 'recipient_group']);
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event', 48);
            $table->string('channel', 16);
            $table->string('locale', 5)->default('tr');
            $table->string('subject', 160)->nullable();
            $table->text('body');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event', 'channel', 'locale']);
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 48);
            $table->string('channel', 16);
            $table->string('recipient', 190); // adres (maskeli gösterilir)
            $table->foreignId('recipient_id')->nullable()->constrained('notification_recipients')->nullOnDelete();
            $table->string('provider', 32)->nullable();
            $table->string('template', 64)->nullable();
            $table->string('subject', 160)->nullable();
            $table->text('body');
            $table->string('status', 16)->default('queued'); // queued|sent|delivered|failed|skipped
            $table->string('provider_message_id', 120)->nullable();
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('provider_message_id');
        });

        // Laravel database notifications (uygulama içi kanal).
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('notification_recipients');
    }
};
