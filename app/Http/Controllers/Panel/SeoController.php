<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestJitAccessRequest;
use App\Models\Website;
use App\Services\AuthorizationService;
use App\Services\ContentCache;
use App\Services\ContentService;
use App\Services\JitAccessService;
use App\Services\SeoService;
use App\Services\WebsiteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SEO Command Center çekirdeği (faz 15, F8 v1).
 *
 *   index     seo.view      — site başına ayarlar + denetim özeti
 *   audit     seo.audit     — içerik denetimi (deterministik kurallar)
 *   settings  seo.settings + JIT ('seo_settings', website id) — robots/canonical
 *   jit       seo.view -> seo.settings için grant açar
 */
class SeoController extends Controller
{
    public const RESOURCE = 'seo_settings';

    public function __construct(
        private readonly SeoService $seo,
        private readonly ContentService $contents,
        private readonly WebsiteService $websites,
        private readonly ContentCache $cache,
        private readonly JitAccessService $jit,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('panel.seo.index', [
            'rows' => $this->contents->allWebsites()->map(fn (Website $site) => [
                'website' => $site,
                'grant' => $this->jit->hasActiveGrant($user, 'seo.settings', self::RESOURCE, $site->id),
                'issues' => count($this->seo->audit($site)),
            ]),
            'canAudit' => $this->authorization->can($user, 'seo.audit'),
            'canRequestJit' => $this->authorization->can($user, 'seo.settings'),
            'defaultTtl' => JitAccessService::DEFAULT_TTL_MINUTES,
            'maxTtl' => JitAccessService::MAX_TTL_MINUTES,
        ]);
    }

    public function audit(Website $website): View
    {
        return view('panel.seo.audit', [
            'website' => $website,
            'findings' => $this->seo->audit($website),
            'sitemap' => $this->seo->sitemapEntries($website),
        ]);
    }

    public function settings(Request $request, Website $website): RedirectResponse
    {
        $validated = $request->validate([
            'seo_title_suffix' => ['nullable', 'string', 'max:80'],
            'seo_default_description' => ['nullable', 'string', 'max:160'],
            'robots_index' => ['sometimes', 'boolean'],
            'seo_locale' => ['required', 'string', 'regex:/^[a-z]{2}_[A-Z]{2}$/'],
        ]);

        $this->websites->updateSeo($website, [
            'seo_title_suffix' => $validated['seo_title_suffix'] ?? null,
            'seo_default_description' => $validated['seo_default_description'] ?? null,
            'robots_index' => (bool) ($validated['robots_index'] ?? false),
            'seo_locale' => $validated['seo_locale'],
        ]);

        // Başlık/robots her sayfayı etkiler: site önbelleği sürüm atlar.
        $this->cache->invalidate($website);

        return redirect()->route('panel.seo.index')->with('status', $website->name.' SEO ayarları güncellendi.');
    }

    /** $izin: settings (varsayılan) · integrations · entity — faz 44 gelişmiş ayar sekmeleri aynı akışı kullanır. */
    public function requestJit(RequestJitAccessRequest $request, Website $website, string $izin = 'settings'): RedirectResponse
    {
        $validated = $request->validated();
        [$permission, $resource, $label] = match ($izin) {
            'integrations' => ['seo.integrations', self::RESOURCE, 'Doğrulama & bildirim ayarları'],
            'entity' => ['geo.settings', GeoController::RESOURCE, 'Varlık ayarları'],
            default => ['seo.settings', self::RESOURCE, 'SEO ayarları'],
        };

        $grantId = $this->jit->grant(
            $request->user(),
            $permission,
            [],
            $resource,
            $website->id,
            $validated['reason'],
            null,
            (int) $validated['ttl_minutes'],
        );

        if ($grantId === null) {
            return back()->withErrors(['reason' => 'JIT erişimi açılamadı: rolünüz '.$permission.' taşımıyor.']);
        }

        return redirect()->to($izin === 'settings' && ! $request->has('sekme') ? route('panel.seo.index') : route('panel.seo.settings.show', [$website, (string) $request->input('sekme', 'tarama')]))
            ->with('status', $label.' için '.$validated['ttl_minutes'].' dakikalık erişim açıldı.');
    }
}
