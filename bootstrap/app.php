<?php

use App\Exceptions\TenantContextException;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\InstallEnvironment;
use App\Http\Middleware\NormalizeTotpCode;
use App\Http\Middleware\PerRequestCaches;
use App\Http\Middleware\PublicCacheHeaders;
use App\Http\Middleware\RequestProfiler;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SiteSeoPolicy;
use App\Http\Middleware\TrustProxies;
use App\Services\CurrentWebsite;
use App\Services\RedirectService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(Illuminate\Http\Middleware\TrustProxies::class, TrustProxies::class); // TRUSTED_PROXIES (audit F-12)
        // Gelen webhook (sunucudan sunucuya, HMAC imzalı): CSRF/origin denetimi dışında. Rota düzeyi withoutMiddleware(ValidateCsrfToken)
        // Laravel 13'ün web grubundaki PreventRequestForgery'yi yakalamıyordu → üretimde 419 (smoke yakaladı; testte CSRF atlanır).
        $middleware->preventRequestForgery(except: ['webhooks/*']);
        $middleware->web(append: [PerRequestCaches::class, SecurityHeaders::class, RequestProfiler::class]); // RequestProfiler (faz 60f): istek profili, terminate'te yazar
        // SiteSeoPolicy (faz 44): vitrin yönlendirme/başlık politikası — GLOBAL, rota eşleşmeden önce çalışır
        // (eski/olmayan adresler de yönlendirilir); panel ve kimlik yolları atlanır.
        // Kurulum sihirbazı (faz 62): /install isteklerinde oturum/önbellek sürücülerini ve APP_DEBUG'ı güvenli
        // değerlere çeker; DB henüz yokken oturum başlatılamazdı. SiteSeoPolicy'den ÖNCE eklenir.
        $middleware->append(InstallEnvironment::class);
        $middleware->append(SiteSeoPolicy::class);

        $middleware->alias([
            'public.cache' => PublicCacheHeaders::class,
            'tenant' => EnsureTenantContext::class,
            'permission' => EnsurePermission::class,
            'staff.2fa' => EnsureStaffTwoFactor::class,
            'totp.normalize' => NormalizeTotpCode::class,
            'account.active' => EnsureAccountActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Doğrulama/iş kuralı hatasında oturuma flash'lanmayacak alanlar: kurulum anahtarı ve şifreler düz metin
        // olarak oturum dosyasına yazılırdı (faz 62 incelemesi).
        $exceptions->dontFlash(['token', 'password', 'password_confirmation', 'current_password', 'mail_password', '_token']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Tarayıcıdan gelen oturum açmış kullanıcı için "context yok" (409) ve
        // "üyelik yok / düşmüş" (403) bir hata sayfası değil, organizasyon
        // seçim ekranıdır. API istemcisi HTTP kodunu aynen alır; 404
        // (outsideActiveTenant) enumeration savunması olarak her yerde 404 kalır.
        $exceptions->render(function (TenantContextException $e, Request $request) {
            if ($request->expectsJson() || $request->user() === null) {
                return null;
            }

            return match ($e->getStatusCode()) {
                // Doğal akış: henüz seçim yapılmamış — mesajsız yönlendir.
                409 => redirect()->route('panel.context.select'),
                // Oturum açıkken üyelik düşmüş/askıya alınmış.
                403 => redirect()->route('panel.context.select')
                    ->with('context_notice', 'Bu organizasyona erişiminiz sona erdi. Devam etmek için yeniden seçim yapın.'),
                default => null,
            };
        });

        // Vitrin 404 (faz 54): URL geçmişi → benzerlik → üst kategori → ana sayfa karar zinciri; yönlendirme yoksa
        // 404 sayfası "belki aradığınız" önerileriyle. Panel/kimlik/asset yolları ve JSON istekleri dokunulmaz.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() || ! $request->isMethod('GET')) {
                return null;
            }

            $path = '/'.trim($request->getPathInfo(), '/');

            foreach (SiteSeoPolicy::SKIP_PREFIXES as $prefix) {
                if ($path === rtrim($prefix, '/') || str_starts_with($path.'/', rtrim($prefix, '/').'/')) {
                    return null;
                }
            }

            $site = app(CurrentWebsite::class)->get();

            if ($site === null) {
                return null;
            }

            $decision = app(RedirectService::class)->onNotFound($site, $path, $request->headers->get('referer'));

            if ($decision['redirect'] !== null) {
                $to = $decision['redirect']['to'];
                $query = $request->getQueryString();

                return redirect()->to(str_starts_with($to, '/') && $query ? $to.'?'.$query : $to, $decision['redirect']['code']);
            }

            return response()->view('errors.404', ['exception' => $e, 'notFoundSuggestions' => $decision['suggestions']], 404);
        });

        // İş kuralı ihlali (DomainException) bir controller'da yakalanmamışsa (faz 52 güvenlik ağı): 500/stack trace
        // yerine forma geri dön ve anlaşılır mesaj göster; API için 422. Ayrıntı log'a, kullanıcıya yalnız mesaj.
        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            if ($request->isMethodSafe()) {
                return null;
            }

            return back()->withErrors(['domain' => $e->getMessage()])->withInput($request->except(['password', 'password_confirmation', 'current_password', 'mail_password', 'token', '_token']));
        });
    })->create();
