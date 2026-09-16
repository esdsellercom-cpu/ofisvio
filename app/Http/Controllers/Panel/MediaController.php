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
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
            'alt' => ['nullable', 'string', 'max:190'],
        ]);

        try {
            $media = $this->media->upload($request->user(), $website, $validated['file'], $validated['alt'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['file' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.content.media.index', ['website' => $website->id])->with('status', $media->original_name.' yüklendi.');
    }

    public function update(Request $request, Media $media): RedirectResponse
    {
        $website = $this->website($request);
        $validated = $request->validate(['alt' => ['nullable', 'string', 'max:190']]);

        $this->media->updateAlt($this->mediaOf($website, $media), $validated['alt'] ?? null);
        $this->cache->invalidate($website);

        return redirect()->route('panel.content.media.index', ['website' => $website->id])->with('status', 'Alt metin güncellendi.');
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
