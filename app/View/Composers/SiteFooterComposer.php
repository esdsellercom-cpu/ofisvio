<?php

namespace App\View\Composers;

use App\Models\Media;
use App\Services\ContentService;
use App\Services\CurrentWebsite;
use App\Services\SiteBlockService;
use App\Services\SiteChromeService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Footer'daki yasal bağlantılar CMS'ten (yayındaki sayfalar). Varsayılan
 * website henüz seed edilmemişse boş liste döner — vitrin CMS yüzünden çökmez.
 */
class SiteFooterComposer
{
    public function __construct(
        private readonly ContentService $contents,
        private readonly SiteBlockService $blocks,
        private readonly CurrentWebsite $website,
        private readonly SiteChromeService $chrome,
        private readonly Request $request,
    ) {}

    public function compose(View $view): void
    {
        $site = $this->website->get();

        // Global footer (faz 61a): panel yapılandırması; önizleme/editörde taslak.
        $data = $view->getData();
        $footer = $site !== null ? $this->chrome->config($site, 'footer', (bool) ($data['preview'] ?? false) || $this->request->attributes->get('ofv.editor') === true) : SiteChromeService::FOOTER_DEFAULTS;
        $footer['logo'] = $site !== null && $footer['logo_media_id'] !== null ? Media::query()->where('website_id', $site->id)->find($footer['logo_media_id']) : null;
        $live = $this->contents->livePages($site);
        $selected = array_values(array_filter($footer['legal']));
        $legalPages = $selected === [] ? $live : $live->filter(fn ($p) => in_array($p->id, $selected, true))->sortBy(fn ($p) => array_search($p->id, $selected, true))->values();

        $view->with([
            'footer' => $footer,
            'legalPages' => $legalPages,
            'blocks' => $this->blocks->all($site),
            'brand' => $site?->brand() ?? ['name' => (string) config('ofisvio.brand.name'), 'legal_name' => (string) config('ofisvio.brand.name'), 'phone' => '', 'phone_href' => '', 'email' => '', 'tagline' => '', 'address' => '', 'whatsapp' => '', 'whatsapp_href' => '', 'hours' => [], 'announcement' => null],
        ]);
    }
}
