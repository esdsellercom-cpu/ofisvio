<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Franchise başvuruları (faz 53): başvuru numarası (FR-YYYY-000001), firma alanı, ad/soyad ayrımı ve genişletilmiş
 * durum seti: new | reviewing | meeting | positive | negative | archived. Eski approved → positive, rejected → negative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franchise_applications', function (Blueprint $table) {
            $table->string('number', 20)->nullable()->unique()->after('id');
            $table->string('first_name', 80)->nullable()->after('name');
            $table->string('last_name', 80)->nullable()->after('first_name');
            $table->string('company', 120)->nullable()->after('last_name');
        });

        DB::table('franchise_applications')->where('status', 'approved')->update(['status' => 'positive']);
        DB::table('franchise_applications')->where('status', 'rejected')->update(['status' => 'negative']);

        // Mevcut kayıtlara numara: id sırasıyla, yıl oluşturma tarihinden.
        foreach (DB::table('franchise_applications')->orderBy('id')->get(['id', 'created_at']) as $i => $row) {
            DB::table('franchise_applications')->where('id', $row->id)->update(['number' => sprintf('FR-%s-%06d', substr((string) $row->created_at, 0, 4) ?: date('Y'), $i + 1)]);
        }
    }

    public function down(): void
    {
        DB::table('franchise_applications')->where('status', 'positive')->update(['status' => 'approved']);
        DB::table('franchise_applications')->where('status', 'negative')->update(['status' => 'rejected']);
        Schema::table('franchise_applications', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn(['number', 'first_name', 'last_name', 'company']);
        });
    }
};
