<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Silme formundaki yönlendirme seçimi (faz 54): suggest = önerilen hedef, custom = girilen adres, none = yönlendirme yok.
     * Doğrulama burada: hedef site içi yol ya da https adresi; aksi halde seçim yok sayılır (DomainException servisten gelir).
     */
    protected static function redirectChoice(Request $request): ?string
    {
        $mode = (string) $request->input('redirect_mode', 'none');
        $target = trim((string) $request->input($mode === 'custom' ? 'redirect_custom' : 'redirect_to', ''));

        if ($mode === 'none' || $target === '' || preg_match('~^(/[^\s]*|https?://[^\s]+)$~', $target) !== 1) {
            return null;
        }

        return $target;
    }
}
