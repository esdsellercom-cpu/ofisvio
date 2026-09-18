<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\AuthorizationService;
use App\Services\ContentService;
use App\Services\MediaService;
use App\Services\SiteBuilderService;
use App\Services\SiteChromeService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Header / Footer ayarları (faz 61a): /panel/ayarlar/header ve /panel/ayarlar/footer. Görüntüleme website.view
 * ya da content.edit; yayın website.manage (global değişiklik tüm sitede). Taslak → imzalı önizleme; yayın sürüm
 * düşer; geri alma. Formdan gelen ham dizi SiteChromeService normalize eder (yalnız izinli adres/renk/medya).
 */
class SiteChromeController extends Controller
{
    public function __construct(
        private readonly SiteChromeService $chrome,
        private readonly ContentService $contents,
        private readonly MediaService $media,
        private readonly SiteBuilderService $builder,
        private readonly AuthorizationService $authorization,
    ) {}

    public function header(Request $request): View
    {
        return $this->form($request, 'header');
    }

    public function footer(Request $request): View
    {
        return $this->form($request, 'footer');
    }

    private function form(Request $request, string $area): View
    {
        $website = $this->website($request);
        $draft = $this->chrome->hasDraft($website, $area);

        return view('panel.settings.chrome', [
            'area' => $area,
            'website' => $website,
            'websites' => $this->contents->allWebsites(),
            'config' => $this->chrome->config($website, $area, true),
            'live' => $this->chrome->config($website, $area, false),
            'hasDraft' => $draft,
            'versions' => $this->chrome->versions($website, $area),
            'mediaOptions' => $this->media->all($website),
            'pages' => $this->contents->livePages($website),
            'social' => SiteChromeService::SOCIAL,
            'canPublish' => $this->authorization->can($request->user(), 'website.manage'),
            'previewUrl' => $this->builder->previewUrl($website),
            'returnTo' => $this->returnPath($request),
        ]);
    }

    /** Taslak kaydet → imzalı önizleme (görsel editör altyapısı). */
    public function preview(Request $request, string $area): RedirectResponse
    {
        $website = $this->website($request);

        try {
            $this->chrome->saveDraft($request->user(), $website, $area, $this->input($request));
        } catch (DomainException $e) {
            return back()->withErrors(['chrome' => $e->getMessage()])->withInput();
        }

        return redirect()->to($this->builder->previewUrl($website->fresh()))->with('status', 'Taslak önizleniyor; yayınlanana kadar ziyaretçi görmez.');
    }

    /** Yayınla (website.manage): tüm sitede geçerli; önce sürüm düşer. */
    public function publish(Request $request, string $area): RedirectResponse
    {
        $website = $this->website($request);

        try {
            $this->chrome->publish($request->user(), $website, $area, $this->input($request), (string) $request->input('note', ''));
        } catch (DomainException $e) {
            return back()->withErrors(['chrome' => $e->getMessage()])->withInput();
        }

        $return = $this->returnPath($request);

        return ($return !== null ? redirect()->to($return) : back())->with('status', ($area === 'header' ? 'Header' : 'Footer').' yayınlandı — tüm sitede uygulandı.');
    }

    public function discard(Request $request, string $area): RedirectResponse
    {
        $this->chrome->discardDraft($request->user(), $this->website($request), $area);

        return back()->with('status', 'Taslak atıldı.');
    }

    public function rollback(Request $request, string $area, int $version): RedirectResponse
    {
        try {
            $this->chrome->rollback($request->user(), $this->website($request), $area, $version);
        } catch (DomainException $e) {
            return back()->withErrors(['chrome' => $e->getMessage()]);
        }

        return back()->with('status', 'Önceki sürüm yayınlandı.');
    }

    private function website(Request $request): Website
    {
        $id = (int) $request->query('website', (int) $request->input('website', 0));

        return $id > 0 ? $this->contents->websiteById($id) : $this->contents->defaultWebsite();
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return (array) $request->input('c', []);
    }

    /** Canlı düzenlemeden gelen dönüş adresi: yalnız site içi yol. */
    private function returnPath(Request $request): ?string
    {
        $return = (string) $request->input('return', (string) $request->query('return', ''));

        return preg_match('~^/(?!/)[^\s]*$~', $return) === 1 && ! str_starts_with($return, '/panel') ? $return : null;
    }
}
