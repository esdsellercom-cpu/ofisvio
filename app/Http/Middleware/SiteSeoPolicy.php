<?php

namespace App\Http\Middleware;

use App\Models\Website;
use App\Services\CurrentWebsite;
use App\Services\SeoSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vitrin URL politikası ve yanıt başlıkları (faz 44 — Canonical & URL, Güvenlik, Geliştirici sekmeleri).
 *
 * İstek öncesi (yalnız GET/HEAD, yalnız vitrin yolları): panelden tanımlı yönlendirmeler (301/302,
 * "/eski/*" ön ek), http→https ve www tercihi (yalnız alan adı tanımlı sitede), sondaki eğik çizgi,
 * küçük harf standardı. Yanıt sonrası: X-Robots-Tag ve özel HTTP başlıkları (güvenlik başlıkları
 * SecurityHeaders'ındır; buradan ezilemez).
 *
 * Panel, giriş, Fortify, webhook, sağlık ve önizleme yolları dokunulmaz — uygulama davranışı değişmez.
 * Host → Website çözümlemesi (eski ResolveWebsite) de burada: global olduğu için vitrin ve panel aynı çözümlemeyi görür.
 */
class SiteSeoPolicy
{
    private const SKIP_PREFIXES = ['/panel', '/login', '/logout', '/register', '/forgot-password', '/reset-password', '/two-factor-challenge', '/user/', '/email/', '/webhooks/', '/up', '/onizleme/', '/build/', '/storage/', '/css/', '/js/', '/images/', '/fonts/'];

    private const PROTECTED_HEADERS = ['content-security-policy', 'strict-transport-security', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'permissions-policy', 'set-cookie', 'content-type', 'content-length', 'location', 'cache-control'];

    public function __construct(private readonly CurrentWebsite $website, private readonly SeoSettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Host -> Website çözümlemesi her istekte buradan (global middleware; çözümleme tembel, ilk get()'te).
        $this->website->setHost($request->getHost());
        $path = '/'.trim($request->getPathInfo(), '/');

        if ($this->skips($path)) {
            return $next($request);
        }

        $site = $this->website->get();

        if ($site === null) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $redirect = $this->redirectFor($request, $site, $path);

            if ($redirect !== null) {
                return $redirect;
            }
        }

        $response = $next($request);
        $this->applyHeaders($response, $site);

        return $response;
    }

    private function skips(string $path): bool
    {
        $path = mb_strtolower($path); // /Login gibi yazımlar da atlanır (küçük harf yönlendirmesi panele uygulanmaz)

        foreach (self::SKIP_PREFIXES as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path.'/', rtrim($prefix, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private function redirectFor(Request $request, Website $site, string $path): ?Response
    {
        $s = $this->settings->for($site);
        $query = $request->getQueryString();
        $suffix = $query !== null && $query !== '' ? '?'.$query : '';

        // 1) Panelden tanımlı yönlendirmeler (tam yol ya da "/on-ek/*"; kalan kısım hedefe eklenir).
        foreach ((array) $s['url.redirects'] as $rule) {
            if (! is_array($rule) || ($rule['from'] ?? '') === '' || ($rule['to'] ?? '') === '') {
                continue;
            }

            $from = rtrim((string) $rule['from'], '/') ?: '/';
            $to = (string) $rule['to'];
            $code = (int) ($rule['code'] ?? 301) === 302 ? 302 : 301;

            if (str_ends_with($from, '/*')) {
                $prefix = substr($from, 0, -2);

                if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                    $rest = substr($path, strlen($prefix));
                    $target = str_ends_with($to, '/*') ? substr($to, 0, -2).$rest : $to;

                    return $this->redirectUnless($request, $target.$suffix, $code);
                }
            } elseif ($path === $from) {
                return $this->redirectUnless($request, $to.$suffix, $code);
            }
        }

        // 2) Şema/alan adı standardı — yalnız alan adı tanımlı sitede (yerel/test kurulumu etkilenmez).
        $host = $request->getHttpHost(); // port dahil (yerel kurulum)
        $targetHost = $host;
        $scheme = $request->getScheme();

        if ($site->domain !== null) {
            if ($s['url.force_https'] && ! $request->isSecure()) {
                $scheme = 'https';
            }

            if ($s['url.www'] === 'www' && ! str_starts_with($host, 'www.')) {
                $targetHost = 'www.'.$host;
            } elseif ($s['url.www'] === 'non_www' && str_starts_with($host, 'www.')) {
                $targetHost = substr($host, 4);
            }
        }

        // 3) Yol standardı.
        $targetPath = $path;

        if ($s['url.lowercase'] && $targetPath !== mb_strtolower($targetPath)) {
            $targetPath = mb_strtolower($targetPath);
        }

        $rawPath = $request->getPathInfo();
        $trailing = $rawPath !== '/' && str_ends_with($rawPath, '/');

        if ($scheme !== $request->getScheme() || $targetHost !== $host || $targetPath !== $path || ($s['url.trailing_slash'] === 'strip' && $trailing)) {
            return $this->redirectUnless($request, $scheme.'://'.$targetHost.$targetPath.$suffix, 301);
        }

        return null;
    }

    /** Hedef geçerli adresle aynıysa döngüye girme. */
    private function redirectUnless(Request $request, string $target, int $code): ?Response
    {
        $absolute = str_starts_with($target, '/') ? $request->getSchemeAndHttpHost().$target : $target;

        // fullUrl() sondaki eğik çizgiyi kırpar; ham istek adresi karşılaştırılır.
        if ($absolute === $request->getSchemeAndHttpHost().$request->getRequestUri()) {
            return null;
        }

        return redirect()->to($absolute, $code);
    }

    private function applyHeaders(Response $response, Website $site): void
    {
        $s = $this->settings->for($site);
        $robots = match ((string) $s['security.x_robots_tag']) {
            'noindex' => 'noindex',
            'noindex_nofollow' => 'noindex, nofollow',
            'auto' => $site->robots_index ? null : 'noindex, nofollow',
            default => null,
        };

        if ($robots !== null) {
            $response->headers->set('X-Robots-Tag', $robots);
        }

        foreach ((array) $s['dev.custom_headers'] as $line) {
            [$name, $value] = array_pad(explode(':', (string) $line, 2), 2, '');
            $name = trim($name);
            $value = trim($value);

            if ($name === '' || $value === '' || preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || in_array(strtolower($name), self::PROTECTED_HEADERS, true) || preg_match('/[\r\n]/', $value) === 1) {
                continue;
            }

            $response->headers->set($name, $value);
        }
    }
}
