<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Para refactor (audit P0-3 / M-1): tüm tutar kolonları TL tam sayıdan KURUŞ (minor unit)
 * tam sayıya geçer — var olan değerler ×100. Kolonlar unsignedBigInteger'a genişler.
 * Gösterim App\Support\Money (money() helper), giriş Money::parse.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> tablo => tutar kolonları */
    private const COLUMNS = [
        'rooms' => ['hourly_rate'],
        'bookings' => ['total_amount'],
        'plans' => ['price'],
        'subscriptions' => ['price'],
        'invoices' => ['subtotal', 'tax_amount', 'total', 'paid_amount'],
        'payments' => ['amount'],
        'events' => ['price'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $column) {
                    $t->unsignedBigInteger($column)->default(0)->change();
                }
            });

            foreach ($columns as $column) {
                DB::table($table)->update([$column => DB::raw("{$column} * 100")]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->update([$column => DB::raw("{$column} / 100")]);
            }
        }
    }
};
