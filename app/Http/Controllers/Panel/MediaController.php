<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Website;
use App\Services\ContentCache;
use App\Services\ContentService;
use App\Services\MediaService;
use App\Services\WebsiteService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Medya kütüphanesi (faz 30). Yükleme/alt metin content.edit; silme content.publish
 * (kullanımda olan görsel silinemez). Hero seçimi website.manage. Site seçimi ?website=.
 * {media} site ile eşleşmezse 404 (tenant/site sınırı).
 */
class MediaController extends Controller
{
    public function __construct(
        private readonly MediaService $media,
        private readonly ContentService $contents,
        private readonly WebsiteService $websites,
        private readonly ContentCache $cache,
    ) {}

    private function website(Request $request): Website
    {
        $id = $request->integer('website');

        return $id > 0 ? $this->contents->websiteById($id) : $this->contents->defaultWebsite();
    }

    private function mediaOf(Website $website, Media $media): Media
    {
        abort_if((int) $media->website_id !== (int) $website->id, 404);

        return $media;
    }

    public function index(Request $request): View
    {
        $website = $this->website($request);

        return view('panel.media.index', [
            'website' => $website,
            'websites' => $this->contents->allWebsites(),
            'items' => $this->media->paginate($website),
            'maxMb' => MediaService::MAX_BYTES / 1048576,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $website = $this->website($request);
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
            'alt' => ['nullable', 'string', 'max:190'],
            'title' => ['nullable', 'string', 'max:160'],
            'caption' => ['nullable', 'string', 'max:300'],
            'seo_name' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'], // SEO uyumlu dosya adı (faz 48)
            'return' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $media = $this->media->upload($request->user(), $website, $validated['file'], ['alt' => $validated['alt'] ?? null, 'title' => $validated['title'] ?? null, 'caption' => $validated['caption'] ?? null, 'seo_name' => $validated['seo_name'] ?? null]);
        } catch (DomainException $e) {
            return back()->withErrors(['file' => $e->getMessage()])->withInput();
        }

        return $this->back($request, $website, $media->original_name.' yüklendi ('.$media->width.'×'.$media->height.' px, '.number_format($media->size_bytes / 1024, 0, ',', '.').' KB).');
    }

    public function update(Request $request, Media $media): RedirectResponse
    {
        $website = $this->website($request);
        $validated = $request->validate(['alt' => ['nullable', 'string', 'max:190'], 'title' => ['nullable', 'string', 'max:160'], 'caption' => ['nullable', 'string', 'max:300'], 'return' => ['nullable', 'string', 'max:500']]);

        $this->media->applyMeta($this->mediaOf($website, $media), array_intersect_key($validated, ['alt' => 1, 'title' => 1, 'caption' => 1]), $request->user());
        $this->cache->invalidate($website);

        return $this->back($request, $website, 'Görsel bilgileri güncellendi.');
    }

    /**
     * Kırpma (faz 48): tarayıcıda canvas ile kırpılan görsel base64 (data URL) olarak gelir; aynı karantina/tarama
     * zincirinden geçip YENİ medya olarak kaydedilir (orijinal korunur; kullanımdaki görsel bozulmaz).
     */
    public function crop(Request $request, Media $media): RedirectResponse
    {
        $website = $this->website($request);
        $source = $this->mediaOf($website, $media);
        $validated = $request->validate(['image' => ['required', 'string', 'max:8000000', 'regex:#^data:image/(jpeg|png|webp);base64,#'], 'return' => ['nullable', 'string', 'max:500']]);

        try {
            $created = $this->media->uploadDataUrl($request->user(), $website, (string) $validated['image'], ['alt' => $source->alt, 'title' => $source->title, 'caption' => $source->caption, 'seo_name' => pathinfo($source->original_name, PATHINFO_FILENAME).'-kirpilmis']);
        } catch (DomainException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return $this->back($request, $website, 'Kırpılmış kopya kaydedildi: '.$created->original_name);
    }

    /** ?return= / return alanı: editöre geri (yalnız panel içi yol); yoksa medya listesi. */
    private function back(Request $request, Website $website, string $message): RedirectResponse
    {
        $return = (string) $request->input('return', '');

        if (str_starts_with($return, '/panel/')) {
            return redirect()->to($return)->with('status', $message);
        }

        return redirect()->route('panel.content.media.index', ['website' => $website->id])->with('status', $message);
    }

    public function destroy(Request $request, Media $media): RedirectResponse
    {
        $website = $this->website($request);

        try {
            $this->media->delete($this->mediaOf($website, $media));
        } catch (DomainException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.media.index', ['website' => $website->id])->with('status', 'Görsel silindi.');
    }

    /** website.manage: sitenin hero görseli (boş = yer tutucu). */
    public function hero(Request $request, Website $website): RedirectResponse
    {
        $validated = $request->validate(['hero_media_id' => ['nullable', 'integer']]);
        $mediaId = (int) ($validated['hero_media_id'] ?? 0);

        if ($mediaId > 0) {
            abort_unless($this->media->belongsTo($website, $mediaId), 404);
        }

        $this->websites->updateHero($website, $mediaId > 0 ? $mediaId : null);
        $this->cache->invalidate($website);

        return redirect()->route('panel.websites.edit', $website)->with('status', 'Hero görseli güncellendi.');
    }
}
