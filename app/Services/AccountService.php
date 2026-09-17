<?php

namespace App\Services;

use App\Models\User;

/**
 * Kullanıcının kendi hesap tercihleri (faz 38). Profil/şifre/2FA Fortify'da;
 * burada yalnız panel tercihleri (tema). Ticari veri değil, audit gerekmez.
 */
class AccountService
{
    /** @param  'light'|'dark'|null  $theme  null = sistem tercihi */
    public function setTheme(User $user, ?string $theme): void
    {
        $user->forceFill(['ui_theme' => $theme])->save();
    }
}
