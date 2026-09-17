@extends('layouts.panel')

@section('title', 'Görseller — '.$location->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.geo.index') }}">GEO</a> · <a href="{{ route('panel.geo.edit', $location) }}">{{ $location->name }}</a></p>
            <h1 class="h2">Görseller</h1>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('site.location', $location->slug) }}" target="_blank" rel="noopener" class="btn btn--ghost">Vitrinde gör</a>
            <a href="{{ route('panel.geo.rooms.index', $location) }}" class="btn btn--ghost">Odalar</a>
        </div>
    </div>

    @foreach (['file', 'media'] as $key)
        @error($key)<div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror
    @endforeach

    <div class="grid-auto" style="--min:320px;--gap:20px;align-items:start">
        <div class="stack" style="gap:16px">
            <div class="panel">
                <p class="eyebrow">Kapak</p>
                @if ($location->cover)
                    <img src="{{ $location->cover->urlFor(960) }}" srcset="{{ $location->cover->srcset() }}" sizes="(max-width: 700px) 100vw, 480px" alt="{{ $location->cover->alt }}" loading="lazy" style="width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:var(--r-md);border:1px solid var(--line)">
                    <p class="small muted" style="margin:8px 0 0">{{ $location->cover->width }}×{{ $location->cover->height }} · {{ number_format($location->cover->size_bytes / 1024) }} KB · {{ count($location->cover->variants ?? []) }} varyant · alt: {{ $location->cover->alt ?? '—' }}</p>
                @else
                    <p class="muted" style="margin:0">Kapak seçilmedi — vitrinde boş durum kutusu görünür. Aşağıdan bir görseli "Kapak yap" ile seçin ya da kapak kategorisine yükleyin.</p>
                @endif
            </div>

            @forelse ($links as $category => $items)
                <div class="panel">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap;margin-bottom:10px">
                        <p class="eyebrow" style="margin:0">{{ $categories[$category] ?? $category }} <span class="muted">({{ $items->count() }})</span></p>
                        <span class="small muted">Sürükleyerek ya da oklarla sıralayın; ◉ birincil (kategoride ilk gösterilen)</span>
                    </div>
                    <form id="reorder-{{ $category }}" method="POST" action="{{ route('panel.geo.media.reorder', $location) }}">@csrf
                        <input type="hidden" name="category" value="{{ $category }}">
                        <input type="hidden" name="order" data-sortable-order value="{{ $items->pluck('id')->implode(',') }}">
                        <noscript><button type="submit" class="btn btn--ghost" style="margin-bottom:8px">Sırayı kaydet</button></noscript>
                    </form>
                    <div data-sortable data-sortable-form="reorder-{{ $category }}">
                        <ol class="stack" style="gap:10px;list-style:none;padding:0;margin:0" data-sortable-list>
                            @foreach ($items as $link)
                                @php($m = $link->media)
                                <li class="card" draggable="true" data-sortable-item="{{ $link->id }}" style="padding:10px;display:grid;grid-template-columns:120px 1fr;gap:12px;align-items:start;border-color:{{ $link->is_primary ? 'var(--brand)' : 'var(--line)' }}">
                                    <a href="{{ $m->url() }}" target="_blank" rel="noopener"><img src="{{ $m->urlFor(480) }}" alt="{{ $m->alt }}" loading="lazy" style="width:120px;aspect-ratio:4/3;object-fit:cover;border-radius:6px;display:block"></a>
                                    <div class="stack" style="gap:8px;min-width:0">
                                        <form method="POST" action="{{ route('panel.geo.media.update', [$location, $link->id]) }}" class="stack" style="gap:6px">@csrf @method('PUT')
                                            <div class="grid-auto" style="--min:140px;--gap:8px">
                                                <input class="control" type="text" name="alt" value="{{ $m->alt }}" maxlength="190" placeholder="Alt metin (erişilebilirlik/SEO)">
                                                <input class="control" type="text" name="title" value="{{ $m->title }}" maxlength="160" placeholder="Başlık">
                                                <input class="control" type="text" name="caption" value="{{ $m->caption }}" maxlength="300" placeholder="Altyazı">
                                            </div>
                                            <div class="row-actions">
                                                <button type="submit" class="btn btn--ghost btn--pill">Bilgileri kaydet</button>
                                                <span class="small muted mono">{{ $m->width }}×{{ $m->height }} · {{ number_format($m->size_bytes / 1024) }} KB{{ $link->is_primary ? ' · birincil' : '' }}{{ (int) $location->cover_media_id === (int) $m->id ? ' · kapak' : '' }}</span>
                                            </div>
                                        </form>
                                        <div class="row-actions">
                                            <button type="submit" form="mv-{{ $link->id }}-up" class="btn btn--ghost btn--pill" title="Yukarı" @disabled($loop->first)>↑</button>
                                            <button type="submit" form="mv-{{ $link->id }}-down" class="btn btn--ghost btn--pill" title="Aşağı" @disabled($loop->last)>↓</button>
                                            @unless ($link->is_primary)<button type="submit" form="pr-{{ $link->id }}" class="btn btn--ghost btn--pill">◉ Birincil yap</button>@endunless
                                            @if ((int) $location->cover_media_id !== (int) $m->id)<button type="submit" form="cv-{{ $link->id }}" class="btn btn--ghost btn--pill">Kapak yap</button>@endif
                                            <button type="submit" form="rm-{{ $link->id }}" class="btn btn--ghost btn--pill" style="color:var(--danger)" onclick="return confirm('Görsel bu kategoriden kaldırılsın mı? Başka yerde kullanılmıyorsa dosya da silinir.')">Kaldır</button>
                                        </div>
                                        <form method="POST" action="{{ route('panel.geo.media.replace', [$location, $link->id]) }}" enctype="multipart/form-data" class="inline-form">@csrf
                                            <input class="control" type="file" name="file" accept="image/jpeg,image/png,image/webp" required style="max-width:260px">
                                            <button type="submit" class="btn btn--ghost btn--pill">Dosyayı değiştir</button>
                                        </form>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                    @foreach ($items as $link)
                        <form id="mv-{{ $link->id }}-up" method="POST" action="{{ route('panel.geo.media.move', [$location, $link->id]) }}" hidden>@csrf<input type="hidden" name="direction" value="up"></form>
                        <form id="mv-{{ $link->id }}-down" method="POST" action="{{ route('panel.geo.media.move', [$location, $link->id]) }}" hidden>@csrf<input type="hidden" name="direction" value="down"></form>
                        <form id="pr-{{ $link->id }}" method="POST" action="{{ route('panel.geo.media.primary', [$location, $link->id]) }}" hidden>@csrf</form>
                        <form id="cv-{{ $link->id }}" method="POST" action="{{ route('panel.geo.media.cover', [$location, $link->id]) }}" hidden>@csrf</form>
                        <form id="rm-{{ $link->id }}" method="POST" action="{{ route('panel.geo.media.destroy', [$location, $link->id]) }}" hidden>@csrf @method('DELETE')</form>
                    @endforeach
                </div>
            @empty
                <div class="panel"><p class="muted" style="margin:0">Henüz görsel yok. Sağdaki formdan yükleyin.</p></div>
            @endforelse
        </div>

        <form method="POST" action="{{ route('panel.geo.media.store', $location) }}" enctype="multipart/form-data" class="panel stack" style="gap:12px">
            @csrf
            <p class="eyebrow" style="margin:0">Görsel yükle</p>
            <p class="small muted" style="margin:0">JPEG/PNG/WebP, ≤ 5 MB. Dosya karantinaya alınır; MIME → uzantı → sihirli bayt → boyut → ClamAV → sha256 zincirinden geçmeden yayınlanmaz. Tarayıcı erişilemezse yükleme reddedilir. 480/960/1600 px responsive kopyalar otomatik üretilir.</p>
            <label class="field"><span class="label">Dosya</span><input class="control" type="file" name="file" accept="image/jpeg,image/png,image/webp" required></label>
            <label class="field"><span class="label">Kategori</span>
                <select class="control" name="category">@foreach ($categories as $k => $label)<option value="{{ $k }}" @selected(old('category', 'gallery') === $k)>{{ $label }}</option>@endforeach</select>
            </label>
            <label class="field"><span class="label">Alt metin</span><input class="control" type="text" name="alt" value="{{ old('alt') }}" maxlength="190" placeholder="Görselin ne gösterdiği (erişilebilirlik)"></label>
            <label class="field"><span class="label">Başlık</span><input class="control" type="text" name="title" value="{{ old('title') }}" maxlength="160"></label>
            <label class="field"><span class="label">Altyazı</span><input class="control" type="text" name="caption" value="{{ old('caption') }}" maxlength="300"></label>
            <label class="checkbox-row"><input type="checkbox" name="primary" value="1" @checked(old('primary'))><span>Kategoride birincil yap</span></label>
            <div><button type="submit" class="btn btn--brand">Yükle</button></div>
        </form>
    </div>
@endsection
