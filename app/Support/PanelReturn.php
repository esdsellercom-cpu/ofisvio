<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Formdan gelen `return` alanı (faz 51): işlem başka bir ekrandan (360° üye profili) tetiklendiyse oraya dönülür.
 * Yalnız panel içi yol kabul edilir (açık yönlendirme yok); yoksa çağıranın varsayılan hedefi.
 */
final class PanelReturn
{
    public static function path(Request $request): ?string
    {
        $return = (string) $request->input('return', '');

        return str_starts_with($return, '/panel/') && ! str_contains($return, '//') ? $return : null;
    }

    public static function to(Request $request, string $fallbackUrl, string $status): RedirectResponse
    {
        return redirect()->to(self::path($request) ?? $fallbackUrl)->with('status', $status);
    }
}
