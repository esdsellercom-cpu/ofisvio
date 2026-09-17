<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\SiteSection;
use App\Models\Website;
use App\Services\ContentService;
use App\Services\SiteBuilderService;
use App\Site\SectionLibrary;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ana sayfa kurucu (master prompt §21–36). Taslak işlemleri content.edit; yayın ve
 * geri alma content.publish (route'ta). {website} personel sitesi; {section} int, siteye
 * süzülür (SiteBuilderService::find). Önizleme imzalı URL ile vitrinde açılır.
 */
class SiteBuilderController extends Controller
{
    public function __construct(
        private readonly SiteBuilderService $builder,
        private readonly ContentService $contents,
    ) {}

    public function index(Request $request): View
    {
        $websites = $this->contents->allWebsites();
        $website = $websites->firstWhere('id', (int) $request->query('website', '0')) ?? $websites->firstWhere('is_default', true) ?? $websites->first();
        $sections = $website ? $this->builder->draft($website) : collect();
        $editing = (int) $request->query('bolum', '0');

        return view('panel.site-builder.index', [
            'websites' => $websites,
            'website' => $website,
            'sections' => $sections,
            'editing' => $editing > 0 ? $sections->firstWhere('id', $editing) : null,
            'library' => SectionLibrary::types(),
            'ctaActions' => SectionLibrary::CTA_ACTIONS,
            'revisions' => $website ? $this->builder->revisions($website) : collect(),
            'hasChanges' => $website ? $this->builder->hasUnpublishedChanges($website) : false,
            'previewUrl' => $website ? $this->builder->previewUrl($website) : null,
            'device' => in_array($request->query('cihaz'), ['desktop', 'tablet', 'mobile'], true) ? (string) $request->query('cihaz') : 'desktop',
        ]);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $v = $request->validate(['type' => ['required', Rule::in(array_keys(SectionLibrary::types()))], 'after' => ['nullable', 'integer']]);

        try {
            $section = $this->builder->add($request->user(), $website, $v['type'], isset($v['after']) ? (int) $v['after'] : null);
        } catch (DomainException $e) {
            return back()->withErrors(['builder' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.builder.index', ['website' => $website->id, 'bolum' => $section->id])->with('status', SectionLibrary::type($v['type'])['label'].' eklendi (taslak).');
    }

    public function update(Request $request, Website $website, int $section): RedirectResponse
    {
        $v = $request->validate([
            'settings' => ['nullable', 'array'], 'anchor' => ['nullable', 'string', 'max:40'],
            'is_visible' => ['nullable', 'boolean'], 'hide_on_mobile' => ['nullable', 'boolean'], 'hide_on_desktop' => ['nullable', 'boolean'],
            'publish_from' => ['nullable', 'string', 'max:30'], 'publish_until' => ['nullable', 'string', 'max:30'],
        ]);

        return $this->act($website, $section, fn ($s) => $this->builder->update($request->user(), $website, $s, $v), 'Bölüm ayarları kaydedildi (taslak).', $section);
    }

    public function move(Request $request, Website $website, int $section): RedirectResponse
    {
        $dir = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        return $this->act($website, $section, fn ($s) => $this->builder->move($request->user(), $website, $s, $dir), 'Sıra güncellendi (taslak).');
    }

    public function reorder(Request $request, Website $website): RedirectResponse
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('order', '')))));
        $this->builder->reorder($request->user(), $website, $ids);

        return redirect()->route('panel.content.builder.index', ['website' => $website->id])->with('status', 'Sıra güncellendi (taslak).');
    }

    public function duplicate(Request $request, Website $website, int $section): RedirectResponse
    {
        return $this->act($website, $section, fn ($s) => $this->builder->duplicate($request->user(), $website, $s), 'Bölüm çoğaltıldı (taslak).');
    }

    public function toggle(Request $request, Website $website, int $section): RedirectResponse
    {
        return $this->act($website, $section, fn ($s) => $this->builder->toggle($request->user(), $website, $s), 'Görünürlük değişti (taslak).');
    }

    public function destroy(Request $request, Website $website, int $section): RedirectResponse
    {
        return $this->act($website, $section, fn ($s) => $this->builder->delete($request->user(), $website, $s), 'Bölüm silindi (taslak).');
    }

    public function publish(Request $request, Website $website): RedirectResponse
    {
        $note = (string) ($request->validate(['note' => ['nullable', 'string', 'max:200']])['note'] ?? '');
        $revision = $this->builder->publish($request->user(), $website, $note);

        return redirect()->route('panel.content.builder.index', ['website' => $website->id])->with('status', 'Yayınlandı: revizyon '.$revision->number.'.');
    }

    public function rollback(Request $request, Website $website, int $revision): RedirectResponse
    {
        try {
            $new = $this->builder->rollback($request->user(), $website, $this->builder->findRevision($website, $revision));
        } catch (DomainException $e) {
            return back()->withErrors(['builder' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.builder.index', ['website' => $website->id])->with('status', 'Geri alındı; yeni revizyon '.$new->number.'.');
    }

    /** @param  callable(SiteSection): mixed  $action */
    private function act(Website $website, int $sectionId, callable $action, string $message, ?int $keepEditing = null): RedirectResponse
    {
        try {
            $action($this->builder->find($website, $sectionId));
        } catch (DomainException $e) {
            return back()->withErrors(['builder' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.content.builder.index', array_filter(['website' => $website->id, 'bolum' => $keepEditing]))->with('status', $message);
    }
}
