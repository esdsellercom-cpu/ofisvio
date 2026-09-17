<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fatura numarası sırası (audit H-2, P0-4): yıl başına tek satır, satır kilidiyle
 * artırılır — "count(like)+1" yarış koşulu yerine atomik sayaç. Var olan numaralardan
 * (ÖNEK-YIL-SIRA) en büyük sıra ile tohumlanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last')->default(0);
            $table->timestamps();
        });

        $rows = DB::table('invoices')->whereNotNull('number')->pluck('number');
        $max = [];

        foreach ($rows as $number) {
            if (preg_match('/^[A-Z]{1,5}-(\d{4})-(\d+)$/', (string) $number, $m) === 1) {
                $max[(int) $m[1]] = max($max[(int) $m[1]] ?? 0, (int) $m[2]);
            }
        }

        foreach ($max as $year => $last) {
            DB::table('invoice_sequences')->insert(['year' => $year, 'last' => $last, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
    }
};
