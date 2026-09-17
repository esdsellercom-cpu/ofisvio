<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 360° üye merkezi (faz 51): üye profili (kişi bilgileri, üye no, avatar), sözleşmeler, ek harcamalar; belgeler
 * sözleşmeye bağlanabilir. Finans/tahsis/üyelik/belge mevcut tablolarda kalır (şirket bazlı).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_role_id')->unique()->constrained('user_roles')->cascadeOnDelete();
            $table->string('member_no', 20)->unique();
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('title', 80)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('identity_number', 20)->nullable(); // TC kimlik / vergi no (kişi)
            $table->string('address', 300)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('membership_type', 80)->nullable();
            $table->date('member_since')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('avatar_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_role_id')->nullable()->constrained('user_roles')->nullOnDelete();
            $table->string('number', 30)->unique();
            $table->string('type', 60);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('active'); // draft | active | ended
            $table->string('file_path', 255)->nullable();   // özel disk (public değil)
            $table->string('file_name', 190)->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 200)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::create('extra_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_role_id')->nullable()->constrained('user_roles')->nullOnDelete();
            $table->string('kind', 30);
            $table->string('description', 200);
            $table->bigInteger('amount'); // kuruş
            $table->string('currency', 3);
            $table->date('charged_on');
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'charged_on']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('payment_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
        });
        Schema::dropIfExists('extra_charges');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('member_profiles');
    }
};
