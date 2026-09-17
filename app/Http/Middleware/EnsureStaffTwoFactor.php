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
 * tek başına yeterli değildir. Internal rol (global ya da lokasyon) taşıyan bir hesap,
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

        // hasInternalRole: global personel VE lokasyon kapsamlı resepsiyon (booking v1).
        if ($user !== null && ! $user->hasConfirmedTwoFactor() && $this->context->hasInternalRole($user)) {
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
