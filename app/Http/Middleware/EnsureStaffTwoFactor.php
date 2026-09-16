<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Personel için 2FA zorunluluğu (faz 5, P0).
 *
 * Ofisvio personeli müşterilerin kimlik belgelerine JIT ile erişir; şifre
 * tek başına yeterli değildir. Global internal rol taşıyan bir hesap,
 * doğrulanmış 2FA olmadan panelin hiçbir müşteri ekranına giremez — yalnızca
 * kurulumun yapıldığı hesap/güvenlik sayfalarına (bkz. routes/panel.php).
 *
 * Müşteri kullanıcıları için isteğe bağlıdır; onlar kendi verisine erişir.
 */
class EnsureStaffTwoFactor
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasConfirmedTwoFactor() && $this->context->isInternalStaff($user)) {
            if ($request->expectsJson()) {
                abort(403, 'Personel hesapları için iki adımlı doğrulama zorunludur.');
            }

            return redirect()
                ->route('panel.account.security')
                ->with('context_notice', 'Personel hesapları için iki adımlı doğrulama zorunludur. Devam etmek için önce 2FA kurun.');
        }

        return $next($request);
    }
}
