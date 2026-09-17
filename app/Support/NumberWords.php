<?php

namespace App\Support;

/**
 * Tutarı Türkçe yazıya çevirir (makbuz "yazıyla" alanı): kuruş cinsinden tam sayı → "üç bin beş yüz lira sıfır kuruş".
 */
final class NumberWords
{
    private const ONES = ['', 'bir', 'iki', 'üç', 'dört', 'beş', 'altı', 'yedi', 'sekiz', 'dokuz'];

    private const TENS = ['', 'on', 'yirmi', 'otuz', 'kırk', 'elli', 'altmış', 'yetmiş', 'seksen', 'doksan'];

    private const SCALES = ['', 'bin', 'milyon', 'milyar', 'trilyon'];

    public static function amount(int $minor, string $currency = 'TRY'): string
    {
        [$unit, $sub] = match (strtoupper($currency)) {
            'EUR' => ['euro', 'sent'],
            'USD' => ['dolar', 'sent'],
            default => ['lira', 'kuruş'],
        };
        $major = intdiv(abs($minor), 100);
        $cents = abs($minor) % 100;

        return trim(($minor < 0 ? 'eksi ' : '').self::integer($major).' '.$unit.' '.self::integer($cents).' '.$sub);
    }

    public static function integer(int $n): string
    {
        if ($n === 0) {
            return 'sıfır';
        }

        $parts = [];
        $scale = 0;

        while ($n > 0) {
            $chunk = $n % 1000;

            if ($chunk > 0) {
                $words = self::chunk($chunk);
                // "bir bin" denmez: "bin"
                if ($scale === 1 && $chunk === 1) {
                    $words = '';
                }
                $parts[] = trim($words.' '.self::SCALES[$scale]);
            }

            $n = intdiv($n, 1000);
            $scale++;
        }

        return trim(implode(' ', array_reverse($parts)));
    }

    private static function chunk(int $n): string
    {
        $hundreds = intdiv($n, 100);
        $tens = intdiv($n % 100, 10);
        $ones = $n % 10;
        $out = [];

        if ($hundreds > 0) {
            $out[] = ($hundreds === 1 ? '' : self::ONES[$hundreds].' ').'yüz';
        }

        if ($tens > 0) {
            $out[] = self::TENS[$tens];
        }

        if ($ones > 0) {
            $out[] = self::ONES[$ones];
        }

        return implode(' ', $out);
    }
}
