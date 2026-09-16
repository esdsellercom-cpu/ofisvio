<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Website;
use App\Services\ContentCache;
use App\Services\SeoService;
use App\Services\TenantContext;
use App\Services\WebsiteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Müşteri sitesi SEO alanı (faz 10 / 15). Matris, şirket kapsamlı:
 *
 *   index    seo.view     — sitenin SEO ayarları, içerik denetimi, sitemap
 *   update   seo.edit     — başlık son eki, varsayılan açıklama, dil
 *   indexing seo.publish  — indekslemeye açma/kapama (tüm siteyi etkiler; yalnız owner)
 *
 * Ofisvio vitrininde aynı alanlar seo.settings + JIT ister; müşteri sitesinde
 * etki alanı kendi sitesidir. {website} int'tir: WebsiteService organizasyona
 * süzer, yabancı site 404.
 */
class SiteSeoController extends Controller
{
    public function __construct(
        private readonly SeoService $seo,
        private readonly WebsiteService $websites,
        private readonly ContentCache $cache,
        private readonly TenantContext $context,
    ) {}

    private function organizationId(Request $request, Company $company): int
    {
        return (int) $this->context->toArray($request->user(), $company->id)['organization_id'];
    }

    private function find(Request $request, Company $company, int $website): Website
    {
        $site = $this->websites->findForOrganization($this->organizationId($request, $company), $website);

        abort_if($site === null, 404);

        return $site;
    }

    public function index(Request $request, Company $company): View
    {
        $user = $request->user();

        return view('panel.site.seo', [
            'company' => $company,
            'rows' => $this->websites->forOrganization($this->organizationId($request, $company))->map(fn (Website $site) => [
                'website' => $site,
                'findings' => $this->seo->audit($site),
                'sitemap' => $this->seo->sitemapEntries($site),
            ]),
            'canEdit' => $user->can('seo.edit', $company),
            'canPublish' => $user->can('seo.publish', $company),
        ]);
    }

    public function update(Request $request, Company $company, int $website): RedirectResponse
    {
        $site = $this->find($request, $company, $website);

        $validated = $request->validate([
            'seo_title_suffix' => ['nullable', 'string', 'max:80'],
            'seo_default_description' => ['nullable', 'string', 'max:160'],
            'seo_locale' => ['required', 'string', 'regex:/^[a-z]{2}_[A-Z]{2}$/'],
        ]);

        $this->websites->updateSeo($site, [
            'seo_title_suffix' => $validated['seo_title_suffix'] ?? null,
            'seo_default_description' => $validated['seo_default_description'] ?? null,
            'robots_index' => $site->robots_index, // indeksleme ayrı izin (seo.publish)
            'seo_locale' => $validated['seo_locale'],
        ]);

        $this->cache->invalidate($site);

        return redirect()->route('panel.companies.site.seo.index', $company)->with('status', $site->name.' SEO ayarları güncellendi.');
    }

    public function indexing(Request $request, Company $company, int $website): RedirectResponse
    {
        $site = $this->find($request, $company, $website);
        $validated = $request->validate(['robots_index' => ['required', 'boolean']]);

        $this->websites->updateSeo($site, [
            'seo_title_suffix' => $site->seo_title_suffix,
            'seo_default_description' => $site->seo_default_description,
            'robots_index' => (bool) $validated['robots_index'],
            'seo_locale' => $site->seo_locale ?: 'tr_TR',
        ]);

        $this->cache->invalidate($site);

        return redirect()->route('panel.companies.site.seo.index', $company)
            ->with('status', $site->name.' '.($validated['robots_index'] ? 'indekslemeye açıldı.' : 'indekslemeye kapatıldı (robots: noindex).'));
    }
}
