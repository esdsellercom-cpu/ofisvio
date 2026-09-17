@extends('layouts.panel')

@section('title', 'Ana sayfa tasarımı')

{{-- Görsel site editörü (faz 49): gerçek vitrin imzalı çerçevede (?editor=1), elemente tıkla → sağ panel, sürükle-bırak,
     inline metin, görsel bırak, undo/redo; tek form gönderimiyle taslak kaydı (payload JSON + görseller). JS HTTP çağrısı yapmaz. --}}
@section('content')
    <style>.ap-view{padding:0}.ap-view .ap-wrap{max-width:none;padding:0;height:100%}</style>
    @if (! $website)
        <div class="empty-state" style="margin:24px">Site yok.</div>
    @else
    @php($sectionRows = $sections->map(fn ($s) => ['id' => $s->id, 'type' => $s->type, 'anchor' => $s->anchor, 'is_visible' => $s->is_visible, 'hide_on_mobile' => $s->hide_on_mobile, 'hide_on_desktop' => $s->hide_on_desktop, 'locked' => $s->locked, 'label' => $s->label, 'preset_id' => $s->preset_id, 'settings' => $s->settings ?? [], 'publish_from' => $s->publish_from?->format('Y-m-d\TH:i'), 'publish_until' => $s->publish_until?->format('Y-m-d\TH:i')])->values())
    @php($config = [
        'websiteId' => $website->id,
        'library' => $library, 'groups' => $groups, 'defaults' => $defaults, 'ctaActions' => $ctaActions, 'styleKeys' => $styleKeys, 'fieldStyleKeys' => $fieldStyleKeys,
        'sections' => $sectionRows, 'texts' => $texts, 'textKeys' => $textKeys, 'footerColumns' => $footerColumns,
        'media' => $mediaOptions, 'presets' => $presets->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type, 'category' => $p->category, 'global' => $p->is_global, 'settings' => $p->settings ?? []])->values(),
        'dataBlocks' => $dataBlocks, 'dataBlockSections' => $dataBlockSections, 'dataBlockMeta' => $dataBlockMeta, 'add' => $add,
        'canPublish' => auth()->user()->can('content.publish'), 'device' => $device, 'selected' => $selected,
        'frameUrl' => $frameUrl, 'revisionPreviewBase' => $revisionPreviewBase, 'previewUrl' => $previewUrl,
    ])
    <div class="ve" data-ve data-config="{{ json_encode($config, JSON_UNESCAPED_UNICODE) }}">
        {{-- ================= ÜST ÇUBUK ================= --}}
        <div class="ve-top">
            <div class="ve-top__group">
                <span class="eyebrow" style="margin:0">Site editörü</span>
                @if ($websites->count() > 1)
                    <form method="GET" class="inline-form"><select class="control" name="website" onchange="this.form.requestSubmit()">@foreach ($websites as $w)<option value="{{ $w->id }}" @selected($w->id === $website->id)>{{ $w->name }}</option>@endforeach</select></form>
                @else
                    <b>{{ $website->name }}</b>
                @endif
                <span class="badge {{ $hasChanges ? 'badge--warn' : 'badge--ok' }}" data-publish-badge>{{ $hasChanges ? 'yayınlanmamış değişiklik' : 'yayınla eşit' }}</span>
                <span class="badge badge--warn" data-dirty-badge hidden>kaydedilmedi</span>
            </div>
            <div class="ve-top__group">
                <button type="button" class="btn btn--brand" data-modal-open="#modal-block-library">+ Blok ekle</button>
                <span class="ve-sep"></span>
                <button type="button" class="btn btn--ghost" data-undo title="Geri al (Ctrl+Z)" disabled>↶ Geri al</button>
                <button type="button" class="btn btn--ghost" data-redo title="Yinele (Ctrl+Y)" disabled>↷ Yinele</button>
                <span class="ve-sep"></span>
                <div class="tabbar" data-device-bar style="border:0">
                    <button type="button" data-device="desktop" aria-selected="{{ $device === 'desktop' ? 'true' : 'false' }}">Masaüstü</button>
                    <button type="button" data-device="tablet" aria-selected="{{ $device === 'tablet' ? 'true' : 'false' }}">Tablet</button>
                    <button type="button" data-device="mobile" aria-selected="{{ $device === 'mobile' ? 'true' : 'false' }}">Mobil</button>
                </div>
                <span class="ve-sep"></span>
                <button type="button" class="btn btn--ghost" data-save title="Taslağı kaydet (Ctrl+S)">Kaydet</button>
                <button type="button" class="btn btn--ghost" data-save-preview title="Kaydet ve gerçek önizlemeyi aç">Önizle</button>
                @can('content.publish')
                    <form method="POST" action="{{ route('panel.content.builder.publish', $website) }}" class="inline-form" data-publish-form>@csrf
                        <input type="hidden" name="note" value="">
                        <button type="submit" class="btn btn--brand" data-publish @disabled(! $hasChanges)>Yayınla</button>
                    </form>
                @endcan
            </div>
        </div>

        @error('builder')<div class="notice notice--error" role="alert" style="margin:10px 12px 0"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror

        <div class="ve-body">
            {{-- ================= SOL PANEL ================= --}}
            <aside class="ve-left">
                <nav class="tabbar ve-tabs" data-left-tabs>
                    <button type="button" data-tab="blocks" aria-selected="true" title="Bloklar">Bloklar</button>
                    <button type="button" data-tab="layers" title="Katmanlar">Katmanlar</button>
                    <button type="button" data-tab="presets" title="Kayıtlı bloklar">Kayıtlı</button>
                    <button type="button" data-tab="pages" title="Sayfalar">Sayfalar</button>
                    <button type="button" data-tab="history" title="Sürümler">Sürümler</button>
                    <button type="button" data-tab="ai" title="AI tasarım yardımcısı">✦ AI</button>
                </nav>
                <div class="ve-left__body">
                    <div data-left-panel="blocks">
                        <p class="small muted" style="margin:0 0 8px">Sayfaya sürükleyin ya da tıklayıp seçili bölümün altına ekleyin. Gerçek veri blokları (hizmet, lokasyon, yazı…) canlı kayıtlardan basılır.</p>
                        @foreach ($groups as $gk => $gl)
                            <p class="eyebrow" style="margin:12px 0 6px">{{ $gl }}</p>
                            <div class="ve-palette">
                                @foreach ($library as $type => $def)
                                    @if ($def['group'] === $gk)
                                        <button type="button" class="ve-chip" draggable="true" data-add-type="{{ $type }}" title="{{ $def['description'] }} · Kaynak: {{ $def['source'] }}"><span class="ve-chip__icon" aria-hidden="true">{{ $def['icon'] }}</span>{{ $def['label'] }}@if ($def['unique'] ?? false)<span class="ve-chip__one" title="Sayfada bir kez">1</span>@endif</button>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <div data-left-panel="layers" hidden>
                        <p class="small muted" style="margin:0 0 8px">Sürükleyerek sıralayın. ◉ göster/gizle · 🔒 kilit · ⧉ kopyala · × sil.</p>
                        <ol class="ve-layers" data-layers></ol>
                    </div>
                    <div data-left-panel="presets" hidden>
                        <p class="small muted" style="margin:0 0 8px">Seçili bölümü sağ panelden <b>Blok olarak kaydet</b> ile ekleyin; buradan sayfaya sürükleyin/ekleyin. Kategori, global bayrağı, önizleme, kullanım: <a href="{{ route('panel.content.blocks') }}">Blok kütüphanesi</a>.</p>
                        <div class="ve-palette" data-preset-list>
                            @forelse ($presets as $p)
                                <div class="ve-chip ve-chip--preset" draggable="true" data-add-type="preset:{{ $p->id }}">
                                    <span class="ve-chip__icon" aria-hidden="true">{{ $library[$p->type]['icon'] ?? '▣' }}</span><span style="flex:1;min-width:0"><b style="display:block;overflow:hidden;text-overflow:ellipsis">{{ $p->is_global ? '🌐 ' : '' }}{{ $p->name }}</b><span class="small muted">{{ $library[$p->type]['label'] ?? $p->type }} · {{ $categories[$p->category] ?? $p->category }}</span></span>
                                    <form method="POST" action="{{ route('panel.content.builder.preset.destroy', [$website, $p->id]) }}" onsubmit="return confirm('Kayıtlı blok silinsin mi?')">@csrf @method('DELETE')<button type="submit" class="btn btn--quiet" title="Sil">×</button></form>
                                </div>
                            @empty
                                <div class="empty-state" style="border:0;padding:12px">Kayıtlı blok yok.</div>
                            @endforelse
                        </div>
                    </div>
                    <div data-left-panel="pages" hidden>
                        <div class="ve-pagelist">
                            <button type="button" class="ve-page is-active" data-open-page="home"><b>Ana sayfa</b><span class="small muted">bölümler · header · footer</span></button>
                            <button type="button" class="ve-page" data-open-global="header"><b>Header &amp; üst şerit</b><span class="small muted">menü etiketleri, CTA — tüm sayfalarda</span></button>
                            <button type="button" class="ve-page" data-open-global="footer"><b>Footer</b><span class="small muted">sütunlar — tüm sayfalarda</span></button>
                            <p class="eyebrow" style="margin:12px 0 6px">Sayfalar</p>
                            @forelse ($pages ?? [] as $page)
                                <div class="ve-page ve-page--row">
                                    <div style="flex:1;min-width:0"><b style="display:block;overflow:hidden;text-overflow:ellipsis">{{ $page->title }}</b><span class="small muted mono">{{ $page->path() }}</span><span class="small" style="display:block;margin-top:4px"><span class="pill {{ $page->status->isLive() ? 'g' : 'n' }} flat">{{ $page->status->label() }}</span> <span class="pill {{ $page->seo_score === null ? 'n' : ($page->seo_score >= 80 ? 'g' : ($page->seo_score >= 50 ? 'w' : 'c')) }} flat" title="SEO skoru">SEO {{ $page->seo_score ?? '—' }}</span> <span class="pill {{ $page->geoReady() ? 'g' : 'n' }} flat" title="GEO">GEO {{ $page->geoReady() ? 'hazır' : 'eksik' }}</span></span></div>
                                    <div class="stack" style="gap:4px">
                                        <a href="{{ route('panel.content.preview', $page) }}" class="btn btn--quiet" title="Gerçek görünüm">Önizle</a>
                                        @can('content.edit')<a href="{{ $page->status->isLive() ? route('panel.content.show', $page) : route('panel.content.edit', $page) }}" class="btn btn--quiet" title="İçerik, SEO, GEO, şema">Stüdyo</a>@endcan
                                    </div>
                                </div>
                            @empty
                                <div class="empty-state" style="border:0;padding:12px">Sayfa yok.</div>
                            @endforelse
                            @can('content.create')
                                <button type="button" class="btn btn--brand" style="margin-top:10px;width:100%" data-modal-open="#modal-new-page">+ Yeni sayfa</button>
                            @endcan
                        </div>
                    </div>
                    <div data-left-panel="history" hidden>
                        <p class="small muted" style="margin:0 0 8px">Her yayın bir sürümdür. Önizle: o sürümün görünümü; Geri dön: sürümü taslağa alır ve yeni sürüm olarak yayınlar.</p>
                        <div class="ve-history">
                            @forelse ($revisions as $rev)
                                <div class="ve-rev">
                                    <div><b>Versiyon {{ $rev->number }}</b> <span class="small muted">· {{ $rev->published_at?->format('d.m.Y H:i') }} · {{ $rev->author?->name ?? '—' }}</span>@if ($rev->note)<div class="small">{{ $rev->note }}</div>@endif</div>
                                    <div style="display:flex;gap:4px">
                                        <button type="button" class="btn btn--quiet" data-preview-revision="{{ $rev->number }}">Önizle</button>
                                        @can('content.publish')<form method="POST" action="{{ route('panel.content.builder.rollback', [$website, $rev->id]) }}" onsubmit="return confirm('Versiyon {{ $rev->number }} taslağa alınıp yeni sürüm olarak yayınlanacak. Devam?')">@csrf<button type="submit" class="btn btn--quiet">Geri dön</button></form>@endcan
                                    </div>
                                </div>
                            @empty
                                <div class="empty-state" style="border:0;padding:12px">Henüz yayın yok.</div>
                            @endforelse
                        </div>
                        <button type="button" class="btn btn--ghost" style="margin-top:8px;width:100%" data-preview-revision="">Taslağa dön</button>
                    </div>
                    <div data-left-panel="ai" hidden>
                        <p class="small muted" style="margin:0 0 8px">✦ Komutu yazın; yardımcı mevcut bileşen sistemiyle bir değişiklik <b>önerisi</b> hazırlar, siz onaylayınca çerçeveye uygulanır (kaydetmeden önce görürsünüz). Kural tabanlıdır; dış AI sağlayıcı bağlandığında aynı öneri arayüzü onu kullanır.</p>
                        <textarea class="control" data-ai-input rows="3" placeholder='Örn. "Hero bölümünü daha modern yap", "Bu bölüme 3 hizmet kartı ekle", "Bu bölümü mobilde iki kolona çevir", "SSS ekle", "Referanslar bölümünü koyu yap"'></textarea>
                        <div style="display:flex;gap:6px;margin-top:6px"><button type="button" class="btn btn--brand" data-ai-run>Öneri hazırla</button><button type="button" class="btn btn--quiet" data-ai-help>Örnekler</button></div>
                        <div class="ve-ai-out" data-ai-out hidden></div>
                    </div>
                </div>
            </aside>

            {{-- ================= TUVAL ================= --}}
            <div class="ve-canvas" data-canvas>
                <div class="ve-frame-wrap" data-frame-wrap data-device="{{ $device }}">
                    <iframe src="{{ $frameUrl }}" title="Site editörü" data-frame></iframe>
                </div>
                <div class="ve-toast" data-toast hidden></div>
            </div>

            {{-- ================= SAĞ PANEL ================= --}}
            <aside class="ve-right">
                <div class="ve-right__head" data-right-head><b>Seçim yok</b><span class="small muted">Sayfada bir bölüme, metne ya da görsele tıklayın.</span></div>
                <nav class="tabbar ve-tabs" data-right-tabs hidden>
                    <button type="button" data-tab="content" aria-selected="true">İçerik</button>
                    <button type="button" data-tab="design">Tasarım</button>
                    <button type="button" data-tab="visibility">Görünürlük</button>
                    <button type="button" data-tab="seo">SEO</button>
                </nav>
                <div class="ve-right__body" data-right-body></div>
            </aside>
        </div>

        {{-- Kayıt formu: payload JSON + bırakılan görseller (DataTransfer ile eklenir). --}}
        <form method="POST" action="{{ route('panel.content.builder.draft', $website) }}?cihaz={{ $device }}" enctype="multipart/form-data" data-save-form hidden>@csrf @method('PUT')
            <textarea name="payload" data-payload></textarea>
            <input type="hidden" name="then" value="stay" data-save-then>
            <div data-upload-slot></div>
        </form>
        <form method="POST" action="{{ route('panel.content.builder.preset.store', $website) }}" data-preset-form hidden>@csrf<input type="hidden" name="name"><input type="hidden" name="type"><input type="hidden" name="settings"></form>

        {{-- + Blok ekle: kütüphane (aynı katalog partial'ı; ekleme JS ile çerçeveye) --}}
        <dialog class="modal" id="modal-block-library" style="width:min(1080px,calc(100vw - 32px))">
            <div class="modal__head"><h2>Blok kütüphanesi</h2><div style="display:flex;gap:8px;align-items:center"><a href="{{ route('panel.content.blocks') }}" class="btn btn--ghost btn--pill">Kütüphaneyi yönet →</a><button type="button" class="btn btn--quiet" data-modal-close>×</button></div></div>
            <div class="modal__body" data-block-library>@include('panel.content._block-catalog', ['mode' => 'picker'])</div>
        </dialog>

        {{-- + Yeni sayfa --}}
        @can('content.create')
        <dialog class="modal" id="modal-new-page">
            <form method="POST" action="{{ route('panel.content.builder.page.store', $website) }}" class="modal__form">@csrf
                <div class="modal__head"><h2>Yeni sayfa</h2><button type="button" class="btn btn--quiet" data-modal-close>×</button></div>
                <div class="modal__body stack" style="gap:10px">
                    <label class="field"><span class="label">Sayfa başlığı</span><input class="control" type="text" name="title" required minlength="3" maxlength="190"></label>
                    <label class="field"><span class="label">Başlangıç</span>
                        <select class="control" name="mode" data-newpage-mode>
                            <option value="blank">Boş sayfa</option>
                            <option value="template">Hazır şablon</option>
                            @if (($pages?->count() ?? 0) > 0)<option value="copy">Mevcut sayfayı kopyala</option>@endif
                        </select>
                    </label>
                    <label class="field" data-newpage-for="template" hidden><span class="label">Şablon</span>
                        <select class="control" name="template">@foreach ($pageTemplates as $k => $t)<option value="{{ $k }}">{{ $t['label'] }} — {{ $t['description'] }}</option>@endforeach</select>
                    </label>
                    <label class="field" data-newpage-for="copy" hidden><span class="label">Kaynak sayfa</span>
                        <select class="control" name="source">@foreach ($pages ?? [] as $page)<option value="{{ $page->id }}">{{ $page->title }} ({{ $page->path() }})</option>@endforeach</select>
                    </label>
                    <p class="small muted" style="margin:0">Sayfa taslak olarak oluşturulur ve CMS stüdyoda açılır (içerik, SEO, GEO, şema, önizleme, yayın).</p>
                </div>
                <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand">Oluştur</button></div>
            </form>
        </dialog>
        @endcan
    </div>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset_v('js/site-editor.js') }}" defer></script>
@endpush
