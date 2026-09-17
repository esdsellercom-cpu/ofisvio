<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\SiteBlockPreset;
use App\Services\ContentService;
use App\Services\SiteBlockService;
use App\Services\SiteBuilderService;
use App\Site\SectionLibrary;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Blok kütüphanesi (faz 50) — `/panel/icerik/bloklar`. Düzenleme burada YAPILMAZ: metinler, veri listeleri ve
 * bölümler görsel editörde (`/panel/icerik/tasarim`) sayfa üzerinde düzenlenir. Bu ekran hazır bileşenleri ve
 * kayıtlı blok şablonlarını listeler (kategori, global bayrağı, kullanım), önizler, kopyalar, adlandırır, siler ve
 * editörde açar. `updateTexts`/`update` uç noktaları faz 10 servis sözleşmesi olarak kalır (yayın yetkisi); arayüzde
 * form yoktur.
 */
class SiteBlockController extends Controller
{
    public function __construct(
        private readonly SiteBlockService $blocks,
        private readonly ContentService $contents,
        private readonly SiteBuilderService $builder,
    ) {}

    public function index(Request $request): View
    {
        $website = $this->contents->defaultWebsite();
        $category = (string) $request->query('kategori', '');

        return view('panel.content.blocks', [
            'website' => $website,
            'library' => SectionLibrary::types(),
            'groups' => SectionLibrary::GROUPS,
            'categories' => SiteBlockPreset::CATEGORIES,
            'category' => isset(SiteBlockPreset::CATEGORIES[$category]) ? $category : '',
            'presets' => $this->builder->presets($website)->when($category !== '' && isset(SiteBlockPreset::CATEGORIES[$category]), fn ($c) => $c->where('category', $category))->values(),
            'usage' => $this->builder->usage($website),
            'hasChanges' => $this->builder->hasUnpublishedChanges($website),
        ]);
    }

    /** Yeni blok şablonu: tip + ad + kategori (+ global); ayarlar tipin varsayılanı, editörde düzenlenir. */
    public function presetStore(Request $request): RedirectResponse
    {
        $v = $request->validate(['name' => ['required', 'string', 'max:80'], 'type' => ['required', Rule::in(array_keys(SectionLibrary::types()))], 'category' => ['required', Rule::in(array_keys(SiteBlockPreset::CATEGORIES))], 'is_global' => ['nullable', 'boolean']]);
        $website = $this->contents->defaultWebsite();

        try {
            $preset = $this->builder->savePreset($request->user(), $website, $v['name'], $v['type'], [], $v['category'], (bool) ($v['is_global'] ?? false));
        } catch (DomainException $e) {
            return back()->withErrors(['builder' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.content.builder.index', ['website' => $website->id, 'ekle' => 'preset:'.$preset->id])->with('status', 'Blok şablonu oluşturuldu; editörde sayfaya eklendi, düzenleyip kaydedin.');
    }

    public function presetUpdate(Request $request, int $preset): RedirectResponse
    {
        $v = $request->validate(['name' => ['required', 'string', 'max:80'], 'category' => ['required', Rule::in(array_keys(SiteBlockPreset::CATEGORIES))], 'is_global' => ['nullable', 'boolean']]);

        try {
            $this->builder->updatePreset($request->user(), $this->contents->defaultWebsite(), $preset, $v['name'], $v['category'], (bool) ($v['is_global'] ?? false));
        } catch (DomainException $e) {
            return back()->withErrors(['builder' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.blocks')->with('status', 'Blok güncellendi.');
    }

    public function presetDuplicate(Request $request, int $preset): RedirectResponse
    {
        try {
            $copy = $this->builder->duplicatePreset($request->user(), $this->contents->defaultWebsite(), $preset);
        } catch (DomainException $e) {
            return back()->withErrors(['builder' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.blocks')->with('status', 'Kopyalandı: '.$copy->name);
    }

    public function presetDestroy(Request $request, int $preset): RedirectResponse
    {
        try {
            $this->builder->deletePreset($request->user(), $this->contents->defaultWebsite(), $preset);
        } catch (DomainException $e) {
            return back()->withErrors(['builder' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.blocks')->with('status', 'Blok silindi; bağlı bölümler kendi kopyalarıyla kaldı.');
    }

    /** Blok önizleme: gerçek bileşen, imzalı vitrin çerçevesinde (`?preset=ID`). */
    public function presetPreview(int $preset): View
    {
        $website = $this->contents->defaultWebsite();

        try {
            $model = $this->builder->findPreset($website, $preset);
        } catch (DomainException) {
            abort(404);
        }

        return view('panel.content.block-preview', [
            'preset' => $model,
            'typeLabel' => SectionLibrary::type($model->type)['label'],
            'frameUrl' => URL::temporarySignedRoute('site.preview', now()->addMinutes(SiteBuilderService::PREVIEW_MINUTES), ['website' => $website->id, 'preset' => $model->id]),
            'editUrl' => route('panel.content.builder.index', ['website' => $website->id, 'ekle' => 'preset:'.$model->id]),
        ]);
    }

    /** Ana sayfa metinleri (faz 29) — servis uç noktası; arayüz görsel editörde (global metinler). */
    public function updateTexts(Request $request): RedirectResponse
    {
        $rules = [];

        foreach (array_keys(SiteBlockService::TEXT_KEYS) as $key) {
            $rules[$key] = ['nullable', 'string', 'max:300'];
        }

        $this->blocks->updateTexts($request->user(), $this->contents->defaultWebsite(), $request->validate($rules));

        return redirect()->route('panel.content.blocks')->with('status', 'Ana sayfa metinleri güncellendi.');
    }

    /** Veri listesi (faz 10) — servis uç noktası; arayüz görsel editörde (ilgili bölüm › veri). */
    public function update(Request $request, string $block): RedirectResponse
    {
        $validated = $request->validate(['text' => ['nullable', 'string', 'max:20000']]);

        try {
            $this->blocks->update($request->user(), $this->contents->defaultWebsite(), $block, (string) ($validated['text'] ?? ''));
        } catch (DomainException $e) {
            return back()->withErrors([$block => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.content.blocks')->with('status', 'Blok güncellendi; vitrin yeni sürümü gösteriyor.');
    }
}
