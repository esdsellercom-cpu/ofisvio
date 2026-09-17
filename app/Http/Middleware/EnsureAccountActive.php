<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Askıya alınmış hesap (users.status = suspended) açık oturumla da panele giremez:
 * oturum kapatılır, girişe yönlendirilir (audit: hesap durumu). Askıya alma anında
 * oturum satırları zaten silinir; bu kapı bellek/dosya sürücüsü ve yarış için.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, 'Hesabınız askıya alındı.');
            }

            return redirect()->route('login')->withErrors(['email' => 'Hesabınız askıya alındı. Bilgi için Ofisvio ile iletişime geçin.']);
        }

        return $next($request);
    }
}
