<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\ContentService;
use App\Services\SiteBlockService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Vitrin blokları (faz 10): Ofisvio ana sayfasının pazarlama listeleri.
 * Yalnızca varsayılan site (müşteri sitelerinin ana sayfası kendi sayfa
 * listesidir). Kaydetme doğrudan canlıya çıkar — yetki content.publish.
 */
class SiteBlockController extends Controller
{
    public function __construct(
        private readonly SiteBlockService $blocks,
        private readonly ContentService $contents,
    ) {}

    public function index(): View
    {
        $website = $this->contents->defaultWebsite();
        $texts = [];

        foreach (array_keys(SiteBlockService::BLOCKS) as $key) {
            $texts[$key] = $this->blocks->text($website, $key);
        }

        $texts['pricing_note'] = $this->blocks->text($website, 'pricing_note');

        return view('panel.content.blocks', [
            'website' => $website,
            'blocks' => SiteBlockService::BLOCKS,
            'texts' => $texts,
            'overridden' => $this->blocks->overridden($website),
        ]);
    }

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
