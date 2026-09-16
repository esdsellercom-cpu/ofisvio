<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestJitAccessRequest;
use App\Models\Website;
use App\Services\AuthorizationService;
use App\Services\ContentCache;
use App\Services\ContentService;
use App\Services\JitAccessService;
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

    public function __construct(
        private readonly ContentCache $cache,
        private readonly ContentService $contents,
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
        ]);

        return view('panel.cache.index', [
            'rows' => $rows,
            'canRequestJit' => $this->authorization->can($user, 'cache.invalidate'),
            'globalGrant' => $this->jit->hasActiveGrant($user, 'cache.invalidate', self::RESOURCE, 0),
            'defaultTtl' => JitAccessService::DEFAULT_TTL_MINUTES,
            'maxTtl' => JitAccessService::MAX_TTL_MINUTES,
        ]);
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

    /** JIT talebi: {website} = 0 global purge içindir. */
    public function requestJit(RequestJitAccessRequest $request, int $website): RedirectResponse
    {
        $validated = $request->validated();

        $grantId = $this->jit->grant(
            $request->user(),
            'cache.invalidate',
            [],
            self::RESOURCE,
            $website,
            $validated['reason'],
            null,
            (int) $validated['ttl_minutes'],
        );

        if ($grantId === null) {
            return back()->withErrors(['reason' => 'JIT erişimi açılamadı: rolünüz cache.invalidate taşımıyor.']);
        }

        return redirect()->route('panel.cache.index')->with('status', 'Geçersizleme için '.$validated['ttl_minutes'].' dakikalık erişim açıldı.');
    }
}
