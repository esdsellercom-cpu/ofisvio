<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PublicCacheHeaders;
use App\Http\Requests\RequestJitAccessRequest;
use App\Models\Website;
use App\Services\AuthorizationService;
use App\Services\ContentCache;
use App\Services\ContentService;
use App\Services\JitAccessService;
use App\Services\WebsiteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Cache Command Center'ın çekirdeği (faz 12/13/14 — v1): site başına sürüm,
 * isabet/ıskalama, ısıtma, JIT'li geçersizleme.
 *
 *   index     cache.view
 *   warm      cache.warm                          (JIT yok)
 *   purge     cache.invalidate  + JIT grant       ('cache', website_id)
 *   purgeAll  cache.invalidate  + JIT grant       ('cache', 0) — YIKICI
 *   jit       cache.view -> grant açar (kyc.view_status/JIT kalıbıyla aynı)
 */
class CacheController extends Controller
{
    public const RESOURCE = 'cache';

    public const SETTINGS_RESOURCE = 'cache_settings';

    public function __construct(
        private readonly ContentCache $cache,
        private readonly ContentService $contents,
        private readonly WebsiteService $websites,
        private readonly JitAccessService $jit,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $websites = $this->contents->allWebsites();

        $rows = $websites->map(fn (Website $site) => [
            'website' => $site,
            'stats' => $this->cache->stats($site),
            'grant' => $this->jit->hasActiveGrant($user, 'cache.invalidate', self::RESOURCE, $site->id),
            'settingsGrant' => $this->jit->hasActiveGrant($user, 'cache.settings', self::SETTINGS_RESOURCE, $site->id),
        ]);

        return view('panel.cache.index', [
            'rows' => $rows,
            'canRequestJit' => $this->authorization->can($user, 'cache.invalidate'),
            'canRequestSettingsJit' => $this->authorization->can($user, 'cache.settings'),
            'canInspect' => $this->authorization->can($user, 'cache.inspect'),
            'defaults' => ['ttl' => ContentCache::TTL_SECONDS, 'max_age' => PublicCacheHeaders::MAX_AGE, 's_maxage' => PublicCacheHeaders::S_MAXAGE],
            'globalGrant' => $this->jit->hasActiveGrant($user, 'cache.invalidate', self::RESOURCE, 0),
            'defaultTtl' => JitAccessService::DEFAULT_TTL_MINUTES,
            'maxTtl' => JitAccessService::MAX_TTL_MINUTES,
        ]);
    }

    /** cache.inspect: kayıtlı anahtarların özeti — içerik değil (matris: hassas olabilir). */
    public function inspect(Website $website): View
    {
        return view('panel.cache.inspect', [
            'website' => $website,
            'stats' => $this->cache->stats($website),
            'ttl' => $this->cache->ttl($website),
            'rows' => $this->cache->inspect($website),
        ]);
    }

    /** cache.settings + JIT ('cache_settings', website): TTL ve HTTP süreleri. */
    public function settings(Request $request, Website $website): RedirectResponse
    {
        $validated = $request->validate([
            'cache_ttl_seconds' => ['nullable', 'integer', 'min:30', 'max:86400'],
            'http_max_age' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'http_s_maxage' => ['nullable', 'integer', 'min:0', 'max:604800'],
        ]);

        $this->websites->updateCacheSettings($website, [
            'cache_ttl_seconds' => isset($validated['cache_ttl_seconds']) ? (int) $validated['cache_ttl_seconds'] : null,
            'http_max_age' => isset($validated['http_max_age']) ? (int) $validated['http_max_age'] : null,
            'http_s_maxage' => isset($validated['http_s_maxage']) ? (int) $validated['http_s_maxage'] : null,
        ]);

        // TTL değişti: eski kayıtlar eski süreyle yaşamasın.
        $this->cache->invalidate($website);

        return redirect()->route('panel.cache.index')->with('status', $website->name.' önbellek ayarları güncellendi.');
    }

    public function warm(Website $website): RedirectResponse
    {
        // Okuma metodları önbelleği doldurur; ilk ziyaretçi ıskalamaz.
        $this->contents->livePosts($website, 3);
        $this->contents->livePosts($website, 50);
        $this->contents->livePages($website);

        return redirect()->route('panel.cache.index')->with('status', $website->name.' önbelleği ısıtıldı.');
    }

    public function purge(Website $website): RedirectResponse
    {
        $version = $this->cache->invalidate($website);

        return redirect()->route('panel.cache.index')->with('status', $website->name.' önbelleği geçersiz kılındı (sürüm '.$version.').');
    }

    public function purgeAll(): RedirectResponse
    {
        $this->cache->purgeAll();

        return redirect()->route('panel.cache.index')->with('status', 'Tüm önbellek boşaltıldı.');
    }

    /** JIT talebi: {website} = 0 global purge içindir; ?izin=settings ile cache.settings grant'i. */
    public function requestJit(RequestJitAccessRequest $request, int $website): RedirectResponse
    {
        $validated = $request->validated();
        $settings = $request->input('izin') === 'settings';

        $grantId = $this->jit->grant(
            $request->user(),
            $settings ? 'cache.settings' : 'cache.invalidate',
            [],
            $settings ? self::SETTINGS_RESOURCE : self::RESOURCE,
            $website,
            $validated['reason'],
            null,
            (int) $validated['ttl_minutes'],
        );

        if ($grantId === null) {
            return back()->withErrors(['reason' => 'JIT erişimi açılamadı: rolünüz '.($settings ? 'cache.settings' : 'cache.invalidate').' taşımıyor.']);
        }

        return redirect()->route('panel.cache.index')->with('status', ($settings ? 'Ayarlar' : 'Geçersizleme').' için '.$validated['ttl_minutes'].' dakikalık erişim açıldı.');
    }
}
