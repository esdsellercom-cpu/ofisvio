<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\LocationMedia;
use App\Services\LocationMediaService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lokasyon görselleri (faz 3) — geo.edit. {link} int, lokasyona süzülür (LocationMediaService::find).
 * Yükleme karantina zincirinden geçer (MediaService); iş kuralı hataları DomainException → form hatası.
 */
class LocationMediaController extends Controller
{
    public function __construct(private readonly LocationMediaService $media) {}

    public function index(Location $location): View
    {
        return view('panel.geo.media', [
            'location' => $location->load('cover'),
            'links' => $this->media->links($location)->groupBy('category'),
            'categories' => LocationMedia::CATEGORIES,
        ]);
    }

    public function store(Request $request, Location $location): RedirectResponse
    {
        $v = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'category' => ['required', Rule::in(array_keys(LocationMedia::CATEGORIES))],
            'alt' => ['nullable', 'string', 'max:190'], 'title' => ['nullable', 'string', 'max:160'], 'caption' => ['nullable', 'string', 'max:300'],
            'primary' => ['nullable', 'boolean'],
        ]);

        try {
            $link = $this->media->upload($request->user(), $location, $request->file('file'), ['category' => $v['category'], 'alt' => $v['alt'] ?? null, 'title' => $v['title'] ?? null, 'caption' => $v['caption'] ?? null, 'primary' => $request->boolean('primary')]);

            if ($v['category'] === 'cover') {
                $this->media->setCover($request->user(), $location, $link);
            }
        } catch (DomainException $e) {
            return back()->withErrors(['file' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.geo.media.index', $location)->with('status', 'Görsel yüklendi ve '.LocationMedia::CATEGORIES[$v['category']].' kategorisine eklendi.');
    }

    public function update(Request $request, Location $location, int $link): RedirectResponse
    {
        $v = $request->validate(['alt' => ['nullable', 'string', 'max:190'], 'title' => ['nullable', 'string', 'max:160'], 'caption' => ['nullable', 'string', 'max:300']]);

        return $this->act($location, $link, fn (LocationMedia $l) => $this->media->updateMeta($request->user(), $location, $l, $v), 'Görsel bilgileri güncellendi.');
    }

    public function cover(Request $request, Location $location, int $link): RedirectResponse
    {
        return $this->act($location, $link, fn (LocationMedia $l) => $this->media->setCover($request->user(), $location, $l), 'Kapak görseli seçildi.');
    }

    public function primary(Request $request, Location $location, int $link): RedirectResponse
    {
        return $this->act($location, $link, fn (LocationMedia $l) => $this->media->setPrimary($request->user(), $location, $l), 'Birincil görsel seçildi.');
    }

    public function move(Request $request, Location $location, int $link): RedirectResponse
    {
        $dir = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        return $this->act($location, $link, fn (LocationMedia $l) => $this->media->move($request->user(), $location, $l, $dir), 'Sıra güncellendi.');
    }

    public function reorder(Request $request, Location $location): RedirectResponse
    {
        $v = $request->validate(['category' => ['required', Rule::in(array_keys(LocationMedia::CATEGORIES))], 'order' => ['required', 'string', 'max:2000']]);
        $ids = array_values(array_filter(array_map('intval', explode(',', $v['order']))));
        $this->media->reorder($request->user(), $location, $v['category'], $ids);

        return redirect()->route('panel.geo.media.index', $location)->with('status', 'Sıra güncellendi.');
    }

    public function replace(Request $request, Location $location, int $link): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        return $this->act($location, $link, fn (LocationMedia $l) => $this->media->replace($request->user(), $location, $l, $request->file('file')), 'Görsel değiştirildi; bilgiler korundu.', 'file');
    }

    public function destroy(Request $request, Location $location, int $link): RedirectResponse
    {
        return $this->act($location, $link, fn (LocationMedia $l) => $this->media->detach($request->user(), $location, $l), 'Görsel kaldırıldı.');
    }

    /** @param  callable(LocationMedia): mixed  $action */
    private function act(Location $location, int $linkId, callable $action, string $message, string $errorKey = 'media'): RedirectResponse
    {
        try {
            $action($this->media->find($location, $linkId));
        } catch (DomainException $e) {
            return back()->withErrors([$errorKey => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.geo.media.index', $location)->with('status', $message);
    }
}
