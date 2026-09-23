<?php

namespace App\Install;

/**
 * Yönetici şifre politikası — TEK KAYNAK. Hem `ofisvio:bootstrap-accounts` (env ile hesap açılışı) hem web kurulum
 * sihirbazı bu sınıfı okur; iki yerde ayrı eşik tutulursa biri gevşer.
 */
final class PasswordPolicy
{
    public const DESCRIPTION = 'en az 16 karakter, büyük ve küçük harf, rakam ve özel karakter';

    public static function ok(string $password): bool
    {
        return strlen($password) >= 16
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1
            && preg_match('/[^A-Za-z0-9]/', $password) === 1;
    }
}
