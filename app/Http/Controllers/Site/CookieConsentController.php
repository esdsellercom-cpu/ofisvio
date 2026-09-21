<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Site\CookieConsent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Çerez tercihi (audit F-06): düz form POST (JS/HTTP çağrısı yok), tercih çerezi yazılır, aynı sayfaya dönülür.
 * Yalnız site içi yola yönlendirir (açık yönlendirme yok). DB yok.
 */
class CookieConsentController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $choice = (string) $request->input('choice') === CookieConsent::ALL ? CookieConsent::ALL : CookieConsent::ESSENTIAL;
        $back = (string) $request->input('return', '/');
        $back = str_starts_with($back, '/') && ! str_starts_with($back, '//') ? $back : '/';

        return redirect($back)->withCookie(CookieConsent::make($choice, $request->isSecure()));
    }
}
