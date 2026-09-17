<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Doğrulayıcı kodu girişini normalize eder: "123 456", "123-456", tam genişlik rakam gibi
 * biçimler 6 haneli düz koda çevrilir. Kod DOĞRULANMAZ (Fortify doğrular); yalnız
 * kullanıcı hatası olmayan biçim farkları giderilir. Kurtarma kodu (recovery_code)
 * yalnız boşluk kırpılır.
 */
class NormalizeTotpCode
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->has('code') && is_string($request->input('code'))) {
            $code = (string) preg_replace('/\D+/u', '', $this->asciiDigits((string) $request->input('code')));
            $request->merge(['code' => $code]);
        }

        if ($request->has('recovery_code') && is_string($request->input('recovery_code'))) {
            $request->merge(['recovery_code' => trim((string) $request->input('recovery_code'))]);
        }

        return $next($request);
    }

    /** Tam genişlik (０-９) ve Arapça-Hint rakamlarını ASCII'ye çevirir. */
    private function asciiDigits(string $value): string
    {
        return strtr($value, [
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4', '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
