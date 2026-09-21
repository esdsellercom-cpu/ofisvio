@extends('layouts.panel')

@section('title', $content ? 'Düzenle — '.$content->title : 'Yeni '.$kind->label())

{{-- CMS stüdyo (faz 48): tek form, sekmeler İçerik · Görseller · İç bağlantı · SEO · GEO · Şema · Yayın.
     $draft (faz 18): yayındaki içeriğin çalışma taslağı; alanlar taslaktan, form draft.update'e gider.
     Müşteri paneli (faz 10) aynı formu kendi rotalarıyla kullanır: $formAction / $indexUrl / $cancelUrl. --}}
@php($draft = $draft ?? null)
@php($src = $draft ?? $content)
@php($formAction = $formAction ?? ($draft ? route('panel.content.draft.update', $content) : ($content ? route('panel.content.update', $content) : route('panel.content.store'))))
@php($indexUrl = $indexUrl ?? route('panel.content.index', ['website' => $website->id, 'kind' => $kind->value]))
@php($cancelUrl = $cancelUrl ?? ($content ? route('panel.content.show', $content) : $indexUrl))
@php($studio = isset($seo) && isset($linkTargets))
@php($mediaOptions = $mediaOptions ?? collect())
@php($publishAction = $publishAction ?? (($draft || ! $studio) ? null : ($content ? route('panel.content.update.publish', $content) : route('panel.content.store.publish'))))
@php($canPublish = $canPublish ?? auth()->user()->can('content.publish'))
@php($mediaReturn = url()->current())

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ $indexUrl }}">İçerik</a> · {{ $website->name }} · {{ $kind->label() }}</p>
            <h1 class="h2">{{ $draft ? 'Çalışma taslağını düzenle' : ($content ? 'Taslağı düzenle' : 'Yeni '.mb_strtolower($kind->label())) }}</h1>
            @if ($draft)<p class="small muted" style="margin:6px 0 0">Yayındaki metin değişmez; taslak akıştan geçip yayınlanınca birleşir.</p>@endif
        </div>
        <div class="panel-head__actions">
            @if ($content)<a href="{{ route('panel.content.show', $content) }}" class="btn btn--ghost">Detay &amp; akış</a>@endif
            @if ($studio && $previewUrl)<a href="{{ $previewUrl }}{{ $draft ? '?draft=1' : '' }}" class="btn btn--ghost">Kayıtlı önizleme</a>@endif
        </div>
    </div>

    @error('status')<div class="notice notice--error" role="alert" style="margin-bottom:16px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror

    <form method="POST" action="{{ $formAction }}" id="cms-form" data-cms-form data-link-targets="{{ json_encode($linkTargets ?? []) }}" data-crop-action="{{ $studio ? route('panel.content.media.crop', ['media' => '__ID__', 'website' => $website->id]) : '' }}">
        @csrf
        @if ($content) @method('PUT') @else <input type="hidden" name="kind" value="{{ $kind->value }}"><input type="hidden" name="website_id" value="{{ $website->id }}"> @endif
        <input type="hidden" name="then" value="save" data-then>

        <nav class="tabbar" aria-label="Editör sekmeleri" data-cms-tabs style="margin-bottom:16px">
            <a href="#sekme-icerik" data-tab="icerik" aria-current="page">İçerik</a>
            @if ($studio)
                <a href="#sekme-gorseller" data-tab="gorseller">Görseller</a>
                <a href="#sekme-baglanti" data-tab="baglanti">İç bağlantı</a>
                <a href="#sekme-seo" data-tab="seo">SEO <span class="pill {{ $seo['score'] >= 80 ? 'g' : ($seo['score'] >= 50 ? 'w' : 'c') }} flat" data-seo-score>{{ $seo['score'] }}</span></a>
                <a href="#sekme-geo" data-tab="geo">GEO / AI</a>
                <a href="#sekme-sema" data-tab="sema">Şema</a>
            @endif
            <a href="#sekme-yayin" data-tab="yayin">Yayın</a>
        </nav>

        {{-- ================= İÇERİK ================= --}}
        <section id="sekme-icerik" data-tab-panel="icerik" class="grid-auto" style="--min:320px;--gap:20px;align-items:start">
            <div class="panel stack" style="gap:14px">
                <label class="field">
                    <span class="label">Sayfa başlığı</span>
                    <input class="control" type="text" name="title" value="{{ old('title', $src?->title) }}" required minlength="3" maxlength="190" autofocus data-seo-field="title" @error('title') aria-invalid="true" @enderror>
                    @error('title')<span class="field-error">{{ $message }}</span>@enderror
                </label>
                <label class="field">
                    <span class="label">Slug / URL (boş bırakılırsa başlıktan üretilir)</span>
                    <input class="control mono" type="text" name="slug" value="{{ old('slug', $src?->slug) }}" maxlength="190" pattern="[a-z0-9-]*" placeholder="{{ $kind->value === 'post' ? 'ornek-yazi' : 'ornek-sayfa' }}" data-seo-field="slug" @error('slug') aria-invalid="true" @enderror>
                    <span class="small muted">{{ $website->baseUrl() }}{{ $kind->value === 'post' ? '/blog/' : '/' }}<b data-slug-preview>{{ old('slug', $src?->slug ?: 'slug') }}</b></span>
                </label>
                <label class="field">
                    <span class="label">Kısa açıklama (özet)</span>
                    <textarea class="control" name="excerpt" maxlength="500" style="min-height:64px" data-seo-field="excerpt" @error('excerpt') aria-invalid="true" @enderror>{{ old('excerpt', $src?->excerpt) }}</textarea>
                </label>

                <div class="field">
                    <span class="label">İçerik</span>
                    <div class="cms-toolbar" data-cms-toolbar role="toolbar" aria-label="Biçimlendirme">
                        <select class="control" data-md="heading" aria-label="Başlık düzeyi" style="width:auto;padding:4px 8px">
                            <option value="">Başlık…</option>
                            <option value="1">H1</option><option value="2">H2</option><option value="3">H3</option><option value="4">H4</option><option value="5">H5</option><option value="6">H6</option>
                        </select>
                        <button type="button" data-md="bold" title="Kalın"><b>B</b></button>
                        <button type="button" data-md="italic" title="İtalik"><i>I</i></button>
                        <button type="button" data-md="ul" title="Liste">• Liste</button>
                        <button type="button" data-md="ol" title="Numaralı liste">1. Liste</button>
                        <button type="button" data-md="quote" title="Alıntı">❝ Alıntı</button>
                        <button type="button" data-md="link" title="Bağlantı">🔗 Link</button>
                        <button type="button" data-md="internal" title="İç bağlantı (site sayfaları)">↳ İç link</button>
                        <button type="button" data-md="image" title="Görsel ekle (Görseller sekmesi)">🖼 Görsel</button>
                        <button type="button" data-md="youtube" title="YouTube video">▶ Video</button>
                        <button type="button" data-md="table" title="Tablo">▦ Tablo</button>
                        <button type="button" data-md="button" title="Buton / CTA">⬚ Buton</button>
                        <button type="button" data-md="code" title="Kod bloğu">&lt;/&gt; Kod</button>
                        <button type="button" data-md="hr" title="Ayırıcı">— Ayırıcı</button>
                        <button type="button" data-md="embed" title="Gömme (YouTube, Vimeo, Google Haritalar)">⧉ Embed</button>
                        <select class="control" data-md="block" aria-label="İçerik bloğu" style="width:auto;padding:4px 8px">
                            <option value="">Blok ekle…</option>
                            @foreach ($blocks ?? [] as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="cms-editor" data-cms-editor>
                        <textarea class="control mono" name="body" id="cms-body" style="min-height:520px;font-size:14px;line-height:1.6" data-seo-field="body" @error('body') aria-invalid="true" @enderror>{{ old('body', $src?->body) }}</textarea>
                        <div class="cms-suggest" data-link-suggest hidden></div>
                    </div>
                    <span class="small muted">Markdown + bloklar: <code>:::hero</code> … <code>:::</code>, görsel hizalama <code>![alt](url){left width=50%}</code>, <code>[youtube:ID]</code>, <code>[button:Metin](/adres)</code>. Ham HTML yayında süzülür.</span>
                </div>
            </div>

            <div class="stack" style="gap:16px">
                <div class="panel stack" style="gap:12px">
                    <p class="eyebrow" style="margin:0">Kapak görseli</p>
                    @if ($mediaOptions->isEmpty())
                        <p class="small muted" style="margin:0">Bu sitede görsel yok — Görseller sekmesinden yükleyin.</p>
                        <input type="hidden" name="cover_media_id" value="">
                    @else
                        <select class="control" name="cover_media_id" data-cover-select @error('cover_media_id') aria-invalid="true" @enderror>
                            <option value="">— Kapak yok</option>
                            @foreach ($mediaOptions as $m)
                                <option value="{{ $m->id }}" data-url="{{ $m->urlFor(640) }}" @selected((int) old('cover_media_id', $content?->cover_media_id) === $m->id)>#{{ $m->id }} · {{ $m->alt ?: $m->original_name }} ({{ $m->width }}×{{ $m->height }})</option>
                            @endforeach
                        </select>
                        <img src="{{ $content?->cover_url ?: '' }}" alt="" data-cover-preview style="width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:var(--r-sm);{{ $content?->cover_url ? '' : 'display:none' }}">
                    @endif
                </div>
                <div class="panel stack" style="gap:12px">
                    <p class="eyebrow" style="margin:0">Sınıflandırma</p>
                    <label class="field"><span class="label">Kategori</span><input class="control" type="text" name="category" value="{{ old('category', $src?->category) }}" maxlength="80" placeholder="Sanal Ofis, Mevzuat…"></label>
                    <label class="field"><span class="label">Etiketler (virgülle, en fazla 10)</span><input class="control" type="text" name="tags" value="{{ old('tags', implode(', ', $src?->tags ?? [])) }}" maxlength="300" placeholder="sanal ofis, tescil"></label>
                    @if ($kind->value === 'page' && ! $draft)
                        <label class="field"><span class="label">Ebeveyn sayfa</span>
                            <select class="control" name="parent_id" @error('parent_id') aria-invalid="true" @enderror>
                                <option value="">— Üst seviye</option>
                                @foreach ($parents ?? [] as $p)<option value="{{ $p->id }}" @selected((int) old('parent_id', $content?->parent_id) === $p->id)>{{ $p->title }} (/{{ $p->slug }})</option>@endforeach
                            </select>
                            <span class="small muted">Alt sayfa /ebeveyn/sayfa adresinde yaşar.</span>
                        </label>
                    @endif
                    <div class="small muted">Yazar: <b>{{ $src?->author?->name ?? auth()->user()->name }}</b>@if ($content) · Oluşturma {{ $content->created_at->format('d.m.Y') }}@endif</div>
                </div>
                @if ($studio)
                    <div class="panel stack" style="gap:8px" data-seo-live>
                        <p class="eyebrow" style="margin:0">Canlı SEO kontrolü</p>
                        <ul class="stack small" style="gap:4px;margin:0;padding:0;list-style:none" data-seo-checks></ul>
                    </div>
                @endif
            </div>
        </section>

        @if ($studio)
        {{-- ================= GÖRSELLER ================= --}}
        <section id="sekme-gorseller" data-tab-panel="gorseller" hidden class="stack" style="gap:16px">
            <div class="grid g-2-1" style="align-items:start">
                <div class="card">
                    <div class="card__head"><h3>Medya kütüphanesi</h3><span class="sub">{{ $mediaOptions->count() }} görsel · ekle / kapak yap / bilgi düzenle / kırp</span></div>
                    @if ($mediaOptions->isEmpty())
                        <div class="empty-state" style="border:0">Henüz görsel yok; sağdan yükleyin.</div>
                    @else
                        <div class="card__body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px">
                            @foreach ($mediaOptions as $m)
                                <div class="cms-media" data-media-id="{{ $m->id }}" data-media-url="{{ $m->url() }}" data-media-alt="{{ $m->alt }}" data-media-title="{{ $m->title }}">
                                    <img src="{{ $m->urlFor(640) }}" alt="{{ $m->alt }}" loading="lazy" data-crop-source>
                                    <div class="small" style="margin-top:6px;overflow-wrap:anywhere"><b>{{ $m->alt ?: 'Alt metin yok' }}</b><br><span class="muted mono">{{ $m->original_name }} · {{ $m->width }}×{{ $m->height }}</span></div>
                                    <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
                                        <button type="button" class="btn btn--quiet" data-insert-image>İçeriğe ekle</button>
                                        <button type="button" class="btn btn--quiet" data-make-cover>Kapak yap</button>
                                        <button type="button" class="btn btn--quiet" data-crop-open>Kırp</button>
                                    </div>
                                    <details style="margin-top:6px">
                                        <summary class="small" style="cursor:pointer">Bilgi düzenle</summary>
                                        <div class="stack" style="gap:6px;margin-top:6px" data-media-meta>
                                            <input class="control" type="text" value="{{ $m->alt }}" maxlength="190" placeholder="Alt metin (SEO)" data-meta-alt>
                                            <input class="control" type="text" value="{{ $m->title }}" maxlength="160" placeholder="Başlık" data-meta-title>
                                            <input class="control" type="text" value="{{ $m->caption }}" maxlength="300" placeholder="Açıklama" data-meta-caption>
                                            <button type="button" class="btn btn--ghost" data-meta-save data-action="{{ route('panel.content.media.update', ['media' => $m, 'website' => $website->id]) }}">Kaydet</button>
                                        </div>
                                    </details>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="stack" style="gap:14px">
                    <div class="card" data-media-upload data-action="{{ route('panel.content.media.store', ['website' => $website->id]) }}">
                        <div class="card__head"><h3>Görsel yükle</h3></div>
                        <div class="card__body stack" style="gap:8px">
                            <p class="small muted" style="margin:0">Yükleme ayrı bir form olarak gönderilir; kaydedilmemiş içerik değişiklikleriniz varsa önce taslağı kaydedin.</p>
                            <input class="control" type="file" accept="image/jpeg,image/png,image/webp" data-upload-file>
                            <input class="control" type="text" placeholder="Alt metin (SEO)" maxlength="190" data-upload-alt>
                            <input class="control" type="text" placeholder="Başlık" maxlength="160" data-upload-title>
                            <input class="control" type="text" placeholder="Açıklama" maxlength="300" data-upload-caption>
                            <input class="control mono" type="text" placeholder="SEO dosya adı (örn. sanal-ofis-istanbul)" maxlength="120" pattern="[a-z0-9-]*" data-upload-seoname>
                            <button type="button" class="btn btn--brand" data-upload-submit>Yükle</button>
                        </div>
                    </div>
                    <div class="card" data-crop-panel hidden>
                        <div class="card__head"><h3>Kırp</h3><span class="sub" data-crop-label></span></div>
                        <div class="card__body stack" style="gap:8px">
                            <canvas data-crop-canvas style="width:100%;border:1px solid var(--line);border-radius:6px;cursor:crosshair"></canvas>
                            <div class="grid-auto" style="--min:120px;--gap:8px">
                                <label class="field"><span class="label">Oran</span><select class="control" data-crop-ratio><option value="">Serbest</option><option value="1.7778">16:9</option><option value="1.3333">4:3</option><option value="1">1:1</option><option value="0.8">4:5</option></select></label>
                                <label class="field"><span class="label">Çıktı genişliği</span><select class="control" data-crop-width><option value="1600">1600</option><option value="1200">1200</option><option value="800">800</option><option value="400">400</option></select></label>
                            </div>
                            <p class="small muted" style="margin:0">Tuvalde sürükleyerek alan seçin; kırpılmış kopya yeni görsel olarak kaydedilir (orijinal korunur).</p>
                            <button type="button" class="btn btn--brand" data-crop-submit>Kırp ve kaydet</button>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card__head"><h3>Görsel yerleşimi</h3></div>
                        <div class="card__body small stack" style="gap:6px">
                            <div><code>![alt](url){left width=40%}</code> — sola yasla</div>
                            <div><code>![alt](url){right width=40%}</code> — sağa yasla</div>
                            <div><code>![alt](url){center width=70%}</code> — ortala</div>
                            <div><code>![alt](url "Alt yazı"){full}</code> — tam genişlik + açıklama</div>
                            <div>Görseli değiştirmek için adresi değiştirin; silmek için satırı kaldırın.</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ================= İÇ BAĞLANTI ================= --}}
        <section id="sekme-baglanti" data-tab-panel="baglanti" hidden class="grid g-2-1" style="align-items:start">
            <div class="stack" style="gap:14px">
                <div class="card">
                    <div class="card__head"><h3>Önerilen iç bağlantılar</h3><span class="sub">Yazarken editörde de önerilir; tıklayınca bağlantı eklenir</span></div>
                    <div class="rows" data-link-list>
                        @forelse ($linkTargets as $t)
                            <div class="row"><div class="main-t"><b>{{ $t['title'] }}</b><span class="mono">{{ $t['path'] }}</span></div><span class="rt"><button type="button" class="btn btn--quiet" data-insert-link="{{ $t['path'] }}" data-insert-text="{{ $t['title'] }}">Bağla</button></span></div>
                        @empty
                            <div class="empty-state" style="border:0">Sitede yayındaki başka sayfa yok.</div>
                        @endforelse
                    </div>
                </div>
                @if ($related !== [])
                    <div class="card">
                        <div class="card__head"><h3>İlgili sayfalar</h3><span class="sub">Ortak etiket / kategori / başlık kelimesi</span></div>
                        <div class="rows">
                            @foreach ($related as $r)
                                <div class="row"><div class="main-t"><b>{{ $r['content']->title }}</b><span>{{ implode(' · ', $r['reasons']) }} · <span class="mono">{{ $r['content']->path() }}</span></span></div><span class="rt"><button type="button" class="btn btn--quiet" data-insert-link="{{ $r['content']->path() }}" data-insert-text="{{ $r['content']->title }}">Bağla</button></span></div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
            <div class="stack" style="gap:14px">
                <div class="card">
                    <div class="card__head"><h3>Bağlantı denetimi</h3><span class="sub">kayıtlı gövdeye göre</span></div>
                    <div class="card__body"><dl class="dl">
                        <dt>Giden iç bağlantı</dt><dd>{{ $linkAudit['outbound'] }}</dd>
                        <dt>Gelen bağlantı</dt><dd>@if ($linkAudit['inbound'] === null)— (kaydedince)@elseif ($linkAudit['inbound'] === 0)<span class="pill w flat">yetim sayfa</span> — başka sayfadan bağlantı yok@else{{ $linkAudit['inbound'] }}@endif</dd>
                        <dt>Kırık bağlantı</dt><dd>@if ($linkAudit['broken'] === [])<span class="pill g flat">yok</span>@else<span class="pill c flat">{{ count($linkAudit['broken']) }}</span> {{ implode(', ', $linkAudit['broken']) }}@endif</dd>
                    </dl></div>
                </div>
                <p class="small muted" style="margin:0">Sitenin tümü için yetim/kırık bağlantı raporu: SEO &amp; GEO › Teknik sekmesi.</p>
            </div>
        </section>

        {{-- ================= SEO ================= --}}
        <section id="sekme-seo" data-tab-panel="seo" hidden class="grid g-2-1" style="align-items:start">
            <div class="panel stack" style="gap:12px">
                <label class="field"><span class="label">SEO başlığı (≤ 70) <span class="muted" data-count-for="meta_title"></span></span><input class="control" type="text" name="meta_title" value="{{ old('meta_title', $src?->meta_title) }}" maxlength="70" data-seo-field="meta_title"></label>
                <label class="field"><span class="label">Meta açıklama (≤ 160) <span class="muted" data-count-for="meta_description"></span></span><textarea class="control" name="meta_description" maxlength="160" style="min-height:64px" data-seo-field="meta_description">{{ old('meta_description', $src?->meta_description) }}</textarea></label>
                <div class="grid-auto" style="--min:200px;--gap:10px">
                    <label class="field"><span class="label">Odak anahtar kelime</span><input class="control" type="text" name="focus_keyword" value="{{ old('focus_keyword', $src?->focus_keyword) }}" maxlength="120" data-seo-field="focus_keyword"></label>
                    <label class="field"><span class="label">İlgili anahtar kelimeler (virgülle)</span><input class="control" type="text" name="related_keywords" value="{{ old('related_keywords', implode(', ', (array) ($src?->related_keywords ?? []))) }}" maxlength="300"></label>
                    <label class="field"><span class="label">Canonical adres</span><input class="control mono" type="text" name="canonical_url" value="{{ old('canonical_url', $src?->canonical_url) }}" maxlength="500" placeholder="boş = sayfanın kendi adresi" data-seo-field="canonical_url"></label>
                    <label class="field"><span class="label">Robots</span>
                        <select class="control" name="robots">
                            @foreach (['' => 'Varsayılan (index, follow)', 'index, follow' => 'index, follow', 'noindex, follow' => 'noindex, follow', 'index, nofollow' => 'index, nofollow', 'noindex, nofollow' => 'noindex, nofollow'] as $v => $label)<option value="{{ $v }}" @selected((string) old('robots', $src?->robots) === $v)>{{ $label }}</option>@endforeach
                        </select>
                    </label>
                </div>
                <label class="checkbox-row"><input type="checkbox" name="noindex" value="1" @checked(old('noindex', $src?->noindex))><span><strong>noindex</strong> — arama motorları bu sayfayı listelemez.</span></label>
                @if ($kind->value === 'post')<label class="checkbox-row"><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $content?->is_featured))><span><strong>Öne çıkan yazı</strong> — ana sayfa blog bölümünde önce listelenir.</span></label>@endif
                <p class="eyebrow" style="margin:8px 0 0">Open Graph</p>
                <div class="grid-auto" style="--min:200px;--gap:10px">
                    <label class="field"><span class="label">OG başlığı</span><input class="control" type="text" name="og_title" value="{{ old('og_title', $src?->og_title) }}" maxlength="120" data-seo-field="og_title"></label>
                    <label class="field"><span class="label">OG görseli</span>
                        <select class="control" name="og_media_id">
                            <option value="">— Kapak görseli</option>
                            @foreach ($mediaOptions as $m)<option value="{{ $m->id }}" @selected((int) old('og_media_id', $src?->og_media_id) === $m->id)>#{{ $m->id }} · {{ $m->alt ?: $m->original_name }}</option>@endforeach
                        </select>
                    </label>
                    <label class="field" style="grid-column:1/-1"><span class="label">OG açıklaması</span><input class="control" type="text" name="og_description" value="{{ old('og_description', $src?->og_description) }}" maxlength="300"></label>
                </div>
                <div class="card" style="background:var(--surface-2)">
                    <div class="card__body">
                        <p class="eyebrow" style="margin:0 0 6px">Arama sonucu önizlemesi</p>
                        <div style="font-family:Arial,sans-serif"><div style="color:#1a0dab;font-size:18px" data-serp-title>{{ old('meta_title', $src?->meta_title ?: $src?->title) ?: 'Başlık' }}</div><div style="color:#006621;font-size:13px" data-serp-url>{{ $website->baseUrl() }}/{{ old('slug', $src?->slug) }}</div><div style="color:#545454;font-size:13px" data-serp-desc>{{ old('meta_description', $src?->meta_description ?: $src?->excerpt) ?: 'Meta açıklama…' }}</div></div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card__head"><h3>SEO analizi</h3><span class="sub">yazarken güncellenir · kayıtta skor saklanır</span></div>
                <div class="card__body">
                    <div class="kpi" style="box-shadow:none;padding:0 0 10px"><span class="k">Skor</span><span class="v" data-seo-score-big>{{ $seo['score'] }}</span><span class="d" data-seo-score-label>{{ \App\Content\SeoAnalyzer::scoreLabel($seo['score']) }}</span></div>
                    <ul class="stack small" style="gap:6px;margin:0;padding:0;list-style:none" data-seo-checks-full>
                        @foreach ($seo['checks'] as $c)<li><span class="pill {{ $c['ok'] ? 'g' : ($c['level'] === 'error' ? 'c' : ($c['level'] === 'warn' ? 'w' : 'n')) }} flat">{{ $c['ok'] ? '✓' : '!' }}</span> {{ $c['message'] }}</li>@endforeach
                    </ul>
                </div>
            </div>
        </section>

        {{-- ================= GEO ================= --}}
        <section id="sekme-geo" data-tab-panel="geo" hidden class="grid g-2-1" style="align-items:start">
            <div class="panel stack" style="gap:12px">
                <div class="note">Öneriler içeriğin kendisinden üretilir (özet, başlıklar, sitedeki lokasyon/hizmet adları, diğer sayfalar) — otomatik kaydedilmez; boş alanları doldurup düzenleyin. AI arama motorları (ChatGPT, Perplexity, Gemini) için özet, sorular ve SSS bu alanlardan JSON-LD/llms verilerine girer.</div>
                <div><button type="button" class="btn btn--ghost" data-geo-fill>Boş alanları önerilerle doldur</button> <button type="button" class="btn btn--quiet" data-geo-fill-all>Tümünü önerilerle değiştir</button></div>
                @foreach ($geoFields as $key => $label)
                    @php($multi = in_array($key, ['entities', 'questions', 'faq', 'answers', 'related'], true))
                    <label class="field"><span class="label">{{ $label }}@if ($multi) <span class="muted">(satır başına bir; SSS: Soru | Cevap)</span>@endif</span>
                        <textarea class="control" name="geo[{{ $key }}]" style="min-height:{{ $multi ? 88 : 56 }}px" data-geo-field="{{ $key }}" data-suggestion="{{ $geoSuggested[$key] }}">{{ $geoForm[$key] }}</textarea>
                    </label>
                @endforeach
            </div>
            <div class="card">
                <div class="card__head"><h3>Öneriler (içerikten)</h3><span class="sub">düzenlenebilir · kilitli değil</span></div>
                <div class="card__body stack small" style="gap:10px">
                    @foreach ($geoFields as $key => $label)
                        <div><b>{{ $label }}</b><div class="muted" style="white-space:pre-wrap">{{ $geoSuggested[$key] !== '' ? $geoSuggested[$key] : '— öneri yok' }}</div></div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ================= ŞEMA ================= --}}
        <section id="sekme-sema" data-tab-panel="sema" hidden class="grid g-1-2" style="align-items:start">
            <div class="panel stack" style="gap:12px">
                <p class="eyebrow" style="margin:0">Şema türleri</p>
                <p class="small muted" style="margin:0">Seçim yoksa varsayılan basılır: {{ $kind->value === 'post' ? 'Article' : 'WebPage' }} + BreadcrumbList (+ gövdede "## Soru?" ya da GEO SSS varsa FAQPage). Seçim yapılırsa yalnız işaretlenenler + Organization yayıncı olarak.</p>
                @foreach ($schemaTypes as $type)
                    <label class="checkbox-row"><input type="checkbox" name="schema_types[]" value="{{ $type }}" @checked(in_array($type, (array) old('schema_types', $src?->schema_types ?? []), true)) data-seo-field="schema_types"><span>{{ $type }}</span></label>
                @endforeach
                <label class="field"><span class="label">Özel JSON-LD (nesne ya da dizi; @graph'a eklenir)</span><textarea class="control mono" name="schema_custom" style="min-height:140px" placeholder='{"@type":"Service","name":"…"}'>{{ old('schema_custom', $src?->schema_custom) }}</textarea>@error('schema_custom')<span class="field-error">{{ $message }}</span>@enderror</label>
            </div>
            <div class="card">
                <div class="card__head"><h3>Üretilen JSON-LD</h3><span class="sub">kayıtlı içerik için · kaydedince güncellenir</span></div>
                <div class="card__body">@if ($schemaPreview)<pre class="mono small" style="margin:0;white-space:pre-wrap;max-height:520px;overflow:auto">{{ $schemaPreview }}</pre>@else<p class="small muted" style="margin:0">İçerik kaydedilince şema burada görünür.</p>@endif</div>
            </div>
        </section>
        @endif

        {{-- ================= YAYIN ================= --}}
        <section id="sekme-yayin" data-tab-panel="yayin" hidden class="grid g-2-1" style="align-items:start">
            <div class="panel stack" style="gap:12px">
                <p class="eyebrow" style="margin:0">Durum</p>
                <dl class="dl">
                    <dt>Durum</dt><dd>{{ $content ? $content->status->label() : 'Yeni taslak' }}@if ($draft) · çalışma taslağı {{ $draft->status->label() }}@endif</dd>
                    <dt>Yayın tarihi</dt><dd>{{ $content?->published_at?->format('d.m.Y H:i') ?? '—' }}@if ($content?->scheduled_for) · zamanlandı {{ $content->scheduled_for->format('d.m.Y H:i') }}@endif</dd>
                    <dt>Yazar</dt><dd>{{ $src?->author?->name ?? auth()->user()->name }}</dd>
                    <dt>Son güncelleme</dt><dd>{{ $src?->updated_at?->format('d.m.Y H:i') ?? '—' }}</dd>
                </dl>
                @if ($draft)
                    <p class="small muted" style="margin:0">Onay bayrağı yayındaki içeriğe aittir{{ $content?->requires_approval ? ' (onay gerekli)' : '' }}.</p>
                @else
                    <label class="checkbox-row">
                        <input type="checkbox" name="requires_approval" value="1" @checked(old('requires_approval', $content?->requires_approval)) @disabled($content?->requires_approval)>
                        <span><strong>Onay gerekli</strong> — yasal, vergi ya da KYC ile ilgili metin. Yayın öncesi ayrıca onaylanmalı.@if ($content?->requires_approval) <span class="muted">(Bir kez işaretlenen geri alınamaz.)</span>@endif</span>
                    </label>
                    @if ($content?->requires_approval)<input type="hidden" name="requires_approval" value="1">@endif
                @endif
                <p class="small muted" style="margin:0">Zamanlama, inceleme ve onay akışı içerik detay sayfasındadır; buradan taslak kaydedilir, önizlenir ya da doğrudan yayınlanır.</p>
            </div>
            <div class="card"><div class="card__body small muted">Yayınla: içerik kaydedilir ve hemen yayına alınır (onay gerektiren içerik önce onaylanmalı; akış detay sayfasında). Önizle: taslak kaydedilip gerçek vitrin görünümü masaüstü/tablet/mobil olarak açılır.</div></div>
        </section>

        <div class="cms-actions" data-cms-actions>
            <span class="small muted" data-dirty-note hidden>Kaydedilmemiş değişiklikler var</span>
            <a href="{{ $cancelUrl }}" class="btn btn--ghost">Vazgeç</a>
            <button type="submit" class="btn btn--ghost" data-then-value="save">Taslak kaydet</button>
            @if ($studio)<button type="submit" class="btn btn--ghost" data-then-value="preview">Önizle</button>@endif
            @if ($publishAction && $canPublish)
                <button type="submit" class="btn btn--brand" formaction="{{ $publishAction }}" data-then-value="publish">Yayınla</button>
            @endif
        </div>
    </form>

    {{-- Medya işlemleri ayrı formlarla (yükleme/meta/kırpma) — içerik formunun dışında, JS ile doldurulur ve gönderilir. --}}
    <form method="POST" enctype="multipart/form-data" data-media-upload-form hidden>@csrf<input type="hidden" name="return" value="{{ $mediaReturn }}"><input type="hidden" name="website" value="{{ $website->id }}"><input type="file" name="file"><input type="hidden" name="alt"><input type="hidden" name="title"><input type="hidden" name="caption"><input type="hidden" name="seo_name"></form>
    <form method="POST" data-media-meta-form hidden>@csrf @method('PUT')<input type="hidden" name="return" value="{{ $mediaReturn }}"><input type="hidden" name="alt"><input type="hidden" name="title"><input type="hidden" name="caption"></form>
    <form method="POST" data-media-crop-form hidden>@csrf<input type="hidden" name="return" value="{{ $mediaReturn }}"><input type="hidden" name="image"></form>
@endsection

@push('scripts')
    <script src="{{ asset_v('js/cms.js') }}" defer></script>
@endpush
