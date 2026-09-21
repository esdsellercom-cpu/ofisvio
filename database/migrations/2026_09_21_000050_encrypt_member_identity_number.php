<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit F-08 (KVKK): TC kimlik / vergi no artık şifreli saklanır (MemberProfile `encrypted` cast). Mevcut düz metin
 * değerler yerinde şifrelenir; kolon metin tipine genişler (şifreli değer uzun). Geri alma: çözüp düz metne döner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->text('identity_number')->nullable()->change();
        });

        foreach (DB::table('member_profiles')->whereNotNull('identity_number')->where('identity_number', '!=', '')->get(['id', 'identity_number']) as $row) {
            $value = (string) $row->identity_number;

            if (strlen($value) <= 20) { // yalnız düz metin (şifreli değer çok daha uzundur) — idempotent
                DB::table('member_profiles')->where('id', $row->id)->update(['identity_number' => Crypt::encryptString($value)]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('member_profiles')->whereNotNull('identity_number')->where('identity_number', '!=', '')->get(['id', 'identity_number']) as $row) {
            $value = (string) $row->identity_number;

            if (strlen($value) > 20) {
                DB::table('member_profiles')->where('id', $row->id)->update(['identity_number' => Crypt::decryptString($value)]);
            }
        }

        Schema::table('member_profiles', function (Blueprint $table) {
            $table->string('identity_number', 20)->nullable()->change();
        });
    }
};
