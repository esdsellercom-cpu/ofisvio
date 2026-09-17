<?php

namespace App\Http\Controllers\Panel;

use App\Content\GeoSuggester;
use App\Content\SeoAnalyzer;
use App\Enums\ContentKind;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\SiteSection;
use App\Models\Website;
use App\Services\ContentService;
use App\Services\MediaService;
use App\Services\SiteBlockService;
use App\Services\SiteBuilderService;
use App\Site\PageTemplates;
use App\Site\SectionLibrary;
use App\Site\SectionStyle;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Görsel site editörü (faz 49; önceki ana sayfa kurucu §21–36 rotaları korunur). Taslak işlemleri content.edit;
 * yayın ve geri alma content.publish (route'ta). {website} personel sitesi; {section} int, siteye süzülür
 * (SiteBuilderService::find). Editör: gerçek vitrin imzalı çerçevede (?editor=1), tüm değişiklik tek form
 * gönderimiyle (`payload` JSON + bırakılan görseller) taslağa yazılır; JS hiçbir HTTP çağrısı yapmaz.
 */
class SiteBuilderController extends Controller
{
    public function __construct(
        private readonly SiteBuilderService $builder,
        private readonly ContentService $contents,
        private readonly SiteBlockService $blocks,
        private readonly MediaService $media,
    ) {}

    public function index(Request $request): View
    {
        $websites = $this->contents->allWebsites();
        $website = $websites->firstWhere('id', (int) $request->query('website', '0')) ?? $websites->firstWhere('is_default', true) ?? $websites->first();
        $sections = $website ? $this->builder->draft($website) : collect();
        $pages = $website ? $this->contents->listFor($website, ContentKind::PAGE, null, null, 100) : null;

        return view('panel.site-builder.index', [
            'websites' => $websites,
            'website' => $website,
            'sections' => $sections,
            'library' => SectionLibrary::types(),
            'defaults' => collect(array_keys(SectionLibrary::types()))->mapWithKeys(fn (string $t) => [$t => SectionLibrary::defaults($t)])->all(),
            'groups' => SectionLibrary::GROUPS,
            'ctaActions' => SectionLibrary::CTA_ACTIONS,
            'styleKeys' => SectionStyle::KEYS,
            'fieldStyleKeys' => SectionStyle::FIELD_KEYS,
            'revisions' => $website ? $this->builder->revisions($website) : collect(),
            'presets' => $website ? $this->builder->presets($website) : collect(),
            'hasChanges' => $website ? $this->builder->hasUnpublishedChanges($website) : false,
            'previewUrl' => $website ? $this->builder->previewUrl($website) : null,
            // Editör çerçevesi: imzalı önizleme + editor=1 (editör betiği yalnız burada yüklenir).
            'frameUrl' => $website ? URL::temporarySignedRoute('site.preview', now()->addMinutes(SiteBuilderService::PREVIEW_MINUTES), ['website' => $website->id, 'editor' => 1]) : null,
            'revisionPreviewBase' => $website ? URL::temporarySignedRoute('site.preview', now()->addMinutes(SiteBuilderService::PREVIEW_MINUTES), ['website' => $website->id]) : null,
            'texts' => $website ? array_merge($this->blocks->texts($website), $this->builder->globalsDraft($website)['texts']) : [],
            'textKeys' => SiteBlockService::TEXT_KEYS,
            'footerColumns' => $website ? ($this->builder->globalsDraft($website)['footer_columns'] ?: $this->blocks->text($website, 'footer_columns')) : '',
            'mediaOptions' => $website ? $this->media->all($website)->map(fn (Media $m) => ['id' => $m->id, 'url' => $m->url(), 'thumb' => $m->urlFor(400), 'alt' => (string) $m->alt, 'name' => $m->original_name, 'w' => $m->width, 'h' => $m->height])->values()->all() : [],
            'pages' => $pages,
            'pageTemplates' => PageTemplates::catalog(),
            'scoreLabel' => fn (?int $s) => SeoAnalyzer::scoreLabel($s),
            'device' => in_array($request->query('cihaz'), ['desktop', 'tablet', 'mobile'], true) ? (string) $request->query('cihaz') : 'desktop',
            'selected' => (int) $request->query('secim', '0'),
        ]);
    }

    /**
     * Editör kaydı (faz 49): tüm taslak tek gönderimde — `payload` JSON (bölümler + global metinler) ve
     * `uploads[token]` bırakılan görseller (karantina zinciri MediaService::upload).
     */
    public function saveDraft(Request $request, Website $website): RedirectResponse
    {
        $v = $request->validate([
            'payload' => ['required', 'string', 'max:1500000'],
            'uploads' => ['nullable', 'array', 'max:20'],
            'uploads.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
            'then' => ['nullable', Rule::in(['stay', 'preview'])],
        ]);
        $payload = json_decode($v['payload'], true);

        if (! is_array($payload)) {
            return back()->withErrors(['builder' => 'Kayıt verisi çözülemedi.']);
        }

        try {
            // Site ana görseli (hero) site ayarıdır: yalnız website.manage taşıyan aktör değiştirebilir; aksi halde yok sayılır.
            $stats = $this->builder->applyDraft($request->user(), $website, $payload, (array) $request->file('uploads', []), $request->user()->can('website.manage'));
        } catch (DomainException $e) {
            return back()->withErrors(['builder' => $e->getMessage()]);
        }

        $message = 'Taslak kaydedildi: '.$stats['sections'].' bölüm'.($stats['created'] > 0 ? ', '.$stats['created'].' yeni' : '').($stats['deleted'] > 0 ? ', '.$stats['deleted'].' silindi' : '').($stats['uploaded'] > 0 ? ', '.$stats['uploaded'].' görsel yüklendi' : '').'.';

        if (($v['then'] ?? 'stay') === 'preview') {
            return redirect()->to($this->builder->previewUrl($website));
        }

        return redirect()->route('panel.content.builder.index', ['website' => $website->id, 'cihaz' => $request->query('cihaz', 'desktop')])->with('status', $message);
    }

    public function presetStore(Request $request, Website $website): RedirectResponse
    {
        $v = $request->validate(['name' => ['required', 'string', 'max:80'], 'type' => ['required', 'string', 'max:40'], 'settings' => ['required', 'string', 'max:200000']]);
        $settings = json_decode($v['settings'], true);

        try {
            $this->builder->savePreset($request->user(), $website, $v['name'], $v['type'], is_array($settings) ? $settings : []);
        } catch (DomainException $e) {
            return back()->withErrors(['builder' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.builder.index', ['website' => $website->id])->with('status', 'Blok kütüphaneye kaydedildi: '.$v['name']);
    }

    public function presetDestroy(Request $request, Website $website, int $preset): RedirectResponse
    {
        try {
            $this->builder->deletePreset($request->user(), $website, $preset);
        } catch (DomainException $e) {
            return back()->withErrors(['builder' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.builder.index', ['website' => $website->id])->with('status', 'Kayıtlı blok silindi.');
    }

    /** "+ Yeni sayfa" (content.create): boş / hazır şablon / mevcut sayfayı kopyala → CMS stüdyoda açılır. */
    public function pageStore(Request $request, Website $website): RedirectResponse
    {
        $v = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:190'],
            'mode' => ['required', Rule::in(['blank', 'template', 'copy'])],
            'template' => ['nullable', Rule::in(array_keys(PageTemplates::catalog()))],
            'source' => ['nullable', 'integer'],
        ]);
        $data = ['kind' => 'page', 'title' => $v['title'], 'body' => ''];

        if ($v['mode'] === 'template' && ! empty($v['template'])) {
            $data['body'] = PageTemplates::catalog()[$v['template']]['body'];
        }

        if ($v['mode'] === 'copy') {
            $source = $this->contents->findForWebsite($website, (int) ($v['source'] ?? 0));

            if ($source === null) {
                return back()->withErrors(['builder' => 'Kopyalanacak sayfa bulunamadı.']);
            }

            $data += array_intersect_key($source->toArray(), array_flip(['excerpt', 'category', 'meta_title', 'meta_description', 'cover_media_id', 'focus_keyword', 'related_keywords', 'canonical_url', 'robots', 'og_title', 'og_description', 'og_media_id', 'geo', 'schema_types', 'schema_custom']));
            $data['body'] = (string) $source->body;
            $data['tags'] = implode(', ', $source->tags ?? []);
            $data['related_keywords'] = implode(', ', (array) ($source->related_keywords ?? []));
            $data['geo'] = GeoSuggester::toForm($source->geo); // kayıt biçimi → form biçimi (ContentService normalize eder)
            $data['canonical_url'] = null;
        }

        try {
            $content = $this->contents->create($request->user(), $website, $data);
        } catch (DomainException $e) {
            return back()->withErrors(['builder' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.edit', $content)->with('status', 'Sayfa oluşturuldu (taslak); içerik, SEO ve GEO alanlarını stüdyoda düzenleyin.');
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $v = $request->validate(['type' => ['required', Rule::in(array_keys(SectionLibrary::types()))], 'after' => ['nullable', 'integer']]);

        try {
            $section = $this->builder->add($request->user(), $website, $v['type'], isset($v['after']) ? (int) $v['after'] : null);
        } catch (DomainException $e) {
            return back()->withErrors(['builder' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.builder.index', ['website' => $website->id, 'secim' => $section->id])->with('status', SectionLibrary::type($v['type'])['label'].' eklendi (taslak).');
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

        return redirect()->route('panel.content.builder.index', array_filter(['website' => $website->id, 'secim' => $keepEditing]))->with('status', $message);
    }
}
