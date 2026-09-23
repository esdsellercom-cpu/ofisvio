<?php

namespace App\Http\Middleware;

use App\Install\InstallGate;
use App\Install\InstallWizardService;
use Closure;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Kurulum isteğinin ortamı (faz 62) — GLOBAL middleware, oturum başlamadan ÖNCE çalışır.
 *
 * Kurulum anında veritabanı henüz yoktur: .env.example SESSION_DRIVER=database, CACHE_STORE=database ve
 * QUEUE_CONNECTION=database ile gelir; bunlar oturum başlatılırken "tablo yok" hatası verirdi. Bu yüzden
 * /install istekleri için sürücüler dosya/eşzamanlıya çekilir. APP_DEBUG de zorla kapatılır: tek yakalanmamış
 * istisna, hata sayfasında .env değerlerini (veritabanı şifresini) basardı.
 *
 * APP_KEY boşsa çerez şifreleme katmanı istisna atar. Kapı AÇIKSA anahtar üretilip .env'e yazılır (tek seferlik,
 * dolu anahtara asla dokunulmaz); kapı KAPALIYSA yalnız bu isteğe ait geçici anahtar kullanılır — yetkisiz
 * ziyaretçinin isteği diske hiçbir şey yazmaz ve 404 ile biter.
 */
class InstallEnvironment
{
    /** Kurulum anında hazır olmayan altyapıya dayanan sürücüler. */
    private const INFRA_DRIVERS = ['database', 'redis', 'memcached', 'dynamodb', 'beanstalkd', 'sqs'];

    public function __construct(private readonly InstallGate $gate, private readonly InstallWizardService $wizard) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('install', 'install/*')) {
            return $next($request);
        }

        return $this->restoring(fn () => $this->handleInstall($request, $next));
    }

    private function handleInstall(Request $request, Closure $next): Response
    {

        Config::set([
            'app.debug' => false,
            'session.secure' => $request->isSecure(),
            'ofisvio.performance.enabled' => false,
        ]);

        // Yalnız veritabanı/Redis gerektiren sürücüler düşürülür (kurulumda tablo da Redis de yok); dosya/array
        // gibi zaten çalışan sürücülere dokunulmaz.
        if (in_array(Config::get('session.driver'), self::INFRA_DRIVERS, true)) {
            Config::set('session.driver', 'file');
        }

        if (in_array(Config::get('cache.default'), self::INFRA_DRIVERS, true)) {
            Config::set('cache.default', 'file');
        }

        if (in_array(Config::get('queue.default'), self::INFRA_DRIVERS, true)) {
            Config::set('queue.default', 'sync');
        }

        if ((string) Config::get('app.key') === '') {
            if (! $this->gate->open()) {
                Config::set('app.key', 'base64:'.base64_encode(random_bytes(32))); // geçici: istek 404 ile bitecek

                return $next($request);
            }

            try {
                $this->wizard->ensureAppKey();
            } catch (DomainException $e) {
                return response()->view('install.blocked', ['reason' => $e->getMessage()], 503);
            } catch (Throwable $e) {
                Log::error('Kurulum: APP_KEY yazılamadı.', ['exception' => $e::class]); // ayrıntı loga, ekrana değil

                return response()->view('install.blocked', ['reason' => 'Yapılandırma dosyası (.env) hazırlanamadı.'], 503);
            }
        }

        return $next($request);
    }

    /**
     * Kurulum isteğini çalıştırır ve config değişikliklerini geri alır: aynı süreçte birden çok isteği işleyen
     * akışlar (ofisvio:smoke kendi içinde HTTP çekirdeğini çağırır) kalıcı olarak dosya önbelleğine düşmemeli.
     * Üretilen APP_KEY korunur — o .env'e yazıldı, artık ortamın gerçek değeridir.
     *
     * @param  callable(): Response  $work
     */
    private function restoring(callable $work): Response
    {
        $original = [
            'app.debug' => Config::get('app.debug'),
            'session.secure' => Config::get('session.secure'),
            'session.driver' => Config::get('session.driver'),
            'cache.default' => Config::get('cache.default'),
            'queue.default' => Config::get('queue.default'),
            'ofisvio.performance.enabled' => Config::get('ofisvio.performance.enabled'),
            'app.key' => Config::get('app.key'),
        ];

        try {
            return $work();
        } finally {
            $key = (string) Config::get('app.key');
            Config::set($original);

            if ((string) $original['app.key'] === '' && $key !== '') {
                Config::set('app.key', $key);
            }
        }
    }
}
