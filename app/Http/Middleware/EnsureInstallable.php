<?php

namespace App\Http\Middleware;

use App\Install\InstallGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kurulum ucunun kapısı (faz 62). Kapalı olmanın her nedeni AYNI 404'ü döndürür: kilit dosyası var, veritabanı
 * dolu, anahtar dosyası yok/kısa/süresi geçmiş, IP farklı ya da anahtar henüz doğrulanmamış. 403 kullanılmaz —
 * "burada kurulmamış bir Ofisvio var" bilgisi dışarı sızmaz.
 *
 * IP karşılaştırması `REMOTE_ADDR` iledir, `Request::ip()` ile DEĞİL: kurulumun site adımında TRUSTED_PROXIES=*
 * yazıldığında X-Forwarded-For başlığıyla çivi atlanabilirdi.
 *
 * Anahtar ekranı (install.token / install.verify) oturum bayrağı istemez; diğer tüm adımlar ister.
 */
class EnsureInstallable
{
    public function __construct(private readonly InstallGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->gate->open(), 404);
        abort_unless($this->gate->ipAllowed(self::clientAddress($request)), 404);

        $name = (string) $request->route()?->getName();

        if (! in_array($name, ['install.token', 'install.verify'], true)) {
            abort_unless($request->session()->get(InstallGate::SESSION_KEY) === true, 404);
        }

        return $next($request);
    }

    /** Ters proxy başlıklarından etkilenmeyen gerçek soket adresi. */
    public static function clientAddress(Request $request): string
    {
        return (string) $request->server('REMOTE_ADDR', '');
    }
}
