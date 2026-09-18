<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\ContentService;
use App\Services\MediaService;
use App\Services\RedirectService;
use App\Services\ServiceService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hizmetler modülü (faz 4) — service.view listeler, service.manage yazar. Hizmet
 * tek kaynaktır: vitrin kartları, lokasyon etiketleri, teklif formu seçenekleri,
 * JSON-LD ve hizmet sayfaları buradan. Kapak görseli medya kütüphanesinden.
 */
class ServiceController extends Controller
{
    public function __construct(
        private readonly ServiceService $services,
        private readonly MediaService $media,
        private readonly ContentService $contents,
        private readonly RedirectService $redirects,
    ) {}

    public function index(): View
    {
        return view('panel.services.index', ['services' => $this->services->all()]);
    }

    public function create(): View
    {
        return view('panel.services.form', ['service' => null, 'kinds' => Service::BOOKING_KINDS, 'mediaOptions' => $this->media->all($this->contents->defaultWebsite())]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $service = $this->services->create($request->user(), $this->validated($request));
        } catch (DomainException $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.services.index')->with('status', $service->name.' eklendi; lokasyonlara GEO › lokasyon künyesinden bağlanır.');
    }

    public function edit(Service $service): View
    {
        return view('panel.services.form', ['service' => $service->load('locations'), 'kinds' => Service::BOOKING_KINDS, 'mediaOptions' => $this->media->all($this->contents->defaultWebsite())]);
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        try {
            $this->services->update($request->user(), $service, $this->validated($request));
        } catch (DomainException $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.services.index')->with('status', $service->name.' güncellendi.');
    }

    /** Silmeden önce (faz 54): /cozum/{slug} için yönlendirme seçimi. */
    public function confirmDelete(Service $service): View
    {
        return view('panel.content.delete', [
            'content' => null,
            'path' => $service->path(),
            'wasLive' => $service->is_active,
            'suggestions' => $this->redirects->suggest($this->contents->defaultWebsite(), $service->path(), RedirectService::snapshotOf($service)),
            'action' => route('panel.services.destroy', $service),
            'cancel' => route('panel.services.index'),
            'label' => $service->name,
        ]);
    }

    public function destroy(Request $request, Service $service): RedirectResponse
    {
        try {
            $this->services->delete($request->user(), $service, self::redirectChoice($request));
        } catch (DomainException $e) {
            return back()->withErrors(['service' => $e->getMessage()]);
        }

        return redirect()->route('panel.services.index')->with('status', 'Hizmet silindi.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'summary' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:10000'],
            'price_text' => ['nullable', 'string', 'max:60'],
            'booking_kind' => ['nullable', Rule::in(array_keys(Service::BOOKING_KINDS))],
            'is_flagship' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'cover_media_id' => ['nullable', 'integer'],
        ]);

        if (! empty($v['cover_media_id']) && ! $this->media->belongsTo($this->contents->defaultWebsite(), (int) $v['cover_media_id'])) {
            throw new DomainException('Kapak görseli medya kütüphanesinde bulunamadı.');
        }

        return $v + ['is_flagship' => $request->boolean('is_flagship'), 'is_active' => $request->boolean('is_active')];
    }
}
