<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Hesap: profil bilgisi, şifre, 2FA. Formlar Fortify'ın kendi route'larına
 * gider (PUT /user/profile-information, PUT /user/password, POST/DELETE
 * /user/two-factor-authentication ...); bu controller yalnızca sayfayı kurar.
 *
 * security(): password.confirm middleware'i taşır. Fortify'ın 2FA route'ları
 * da bu middleware'i ister; onay ekranından dönüş GET ile olduğu için formlar
 * POST'tan değil bu GET sayfasından açılır — onay bir kez alınır
 * (auth.password_timeout), sonra POST'lar geçer.
 */
class AccountController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(Request $request): View
    {
        return view('panel.account.show', [
            'user' => $request->user(),
            'isStaffUser' => $this->context->isInternalStaff($request->user()),
        ]);
    }

    public function security(Request $request): View
    {
        $user = $request->user();
        $enabled = $user->two_factor_secret !== null;
        $confirmed = $user->hasConfirmedTwoFactor();

        return view('panel.account.security', [
            'user' => $user,
            'enabled' => $enabled,
            'confirmed' => $confirmed,
            // Kurulum aşamasında QR + anahtar; doğrulandıktan sonra kurtarma kodları.
            'qrCode' => $enabled && ! $confirmed ? $user->twoFactorQrCodeSvg() : null,
            'secretKey' => $enabled && ! $confirmed ? decrypt($user->two_factor_secret) : null,
            'recoveryCodes' => $confirmed ? $user->recoveryCodes() : [],
        ]);
    }
}
