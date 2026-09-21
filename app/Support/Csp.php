<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * CSP nonce (audit F-11): istek başına tek rastgele değer; SecurityHeaders başlığa, görünümler `<script nonce>`'a yazar.
 * `script-src` artık 'unsafe-inline' taşımaz — nonce'suz satır içi script çalışmaz (XSS'te enjekte edilen script de).
 * Satır içi olay öznitelikleri (onclick/onsubmit) yasak: panel.js / ofisvio.js `data-confirm`, `data-autosubmit` ile çözer.
 * Yönetici "geliştirici alanı" HTML'i (head_code/body_*) `withNonce()` ile geçer: JIT'li ayar, güvenilir kaynak.
 */
final class Csp
{
    private static ?string $nonce = null;

    public static function nonce(): string
    {
        return self::$nonce ??= Str::random(24);
    }

    /** Test/kuyruk: istekler arası sızmasın. */
    public static function reset(): void
    {
        self::$nonce = null;
    }

    /** Yönetici HTML'indeki nonce'suz <script> etiketlerine nonce ekler. */
    public static function withNonce(string $html): string
    {
        if ($html === '' || ! str_contains($html, '<script')) {
            return $html;
        }

        return (string) preg_replace_callback('/<script\b(?![^>]*\bnonce=)/i', fn () => '<script nonce="'.self::nonce().'"', $html);
    }
}
