<?php

use App\Exceptions\TenantContextException;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\NormalizeTotpCode;
use App\Http\Middleware\PerRequestCaches;
use App\Http\Middleware\PublicCacheHeaders;
use App\Http\Middleware\ResolveWebsite;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Çoklu website: Host -> Website çözümlemesi her web isteğinde.
        $middleware->web(append: [ResolveWebsite::class, PerRequestCaches::class, SecurityHeaders::class]);

        $middleware->alias([
            'public.cache' => PublicCacheHeaders::class,
            'tenant' => EnsureTenantContext::class,
            'permission' => EnsurePermission::class,
            'staff.2fa' => EnsureStaffTwoFactor::class,
            'totp.normalize' => NormalizeTotpCode::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
    })->create();
