@extends('layouts.panel')

@section('title', 'Medya')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">İçerik · {{ $website->name }}</p>
            <h1 class="h2">Medya kütüphanesi</h1>
        </div>
    </div>

    @if ($websites->count() > 1)
        <form method="GET" class="inline-form" style="margin-bottom:18px">
            <label class="field" style="flex:0 1 220px"><span class="label">Site</span>
                <select class="control" name="website" onchange="this.form.submit()">
                    @foreach ($websites as $site)
                        <option value="{{ $site->id }}" @selected($site->id === $website->id)>{{ $site->name }}</option>
                    @endforeach
                </select>
            </label>
        </form>
    @endif

    @can('content.edit')
        <form method="POST" action="{{ route('panel.content.media.store', ['website' => $website->id]) }}" enctype="multipart/form-data" class="panel inline-form" style="margin-bottom:22px;align-items:end">
            @csrf
            <label class="field" style="flex:1 1 260px"><span class="label">Görsel (JPEG/PNG/WebP, ≤ {{ $maxMb }} MB)</span>
                <input class="control" type="file" name="file" accept="image/jpeg,image/png,image/webp" required @error('file') aria-invalid="true" @enderror>
            </label>
            <label class="field" style="flex:1 1 220px"><span class="label">Alt metin (erişilebilirlik/SEO)</span>
                <input class="control" type="text" name="alt" value="{{ old('alt') }}" maxlength="190">
            </label>
            <button type="submit" class="btn btn--brand">Yükle</button>
            @error('file')<p class="small" style="color:var(--danger);flex-basis:100%;margin:0">{{ $message }}</p>@enderror
        </form>
    @endcan

    @if ($items->isEmpty())
        <div class="empty-state">Bu sitede görsel yok. Yüklenen görseller içerik kapağı ve ana sayfa hero'su olarak seçilir.</div>
    @else
        <div class="grid-auto" style="--min:200px;--gap:14px">
            @foreach ($items as $m)
                <div class="panel" style="padding:12px">
                    <img src="{{ $m->url() }}" alt="{{ $m->alt ?? $m->original_name }}" style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:var(--r-sm);display:block" loading="lazy">
                    <div class="small mono muted" style="margin-top:8px">{{ $m->width }}×{{ $m->height }} · {{ number_format($m->size_bytes / 1024) }} KB · #{{ $m->id }}</div>
                    <div class="small" style="margin-top:2px">{{ $m->original_name }}</div>
                    @can('content.edit')
                        <form method="POST" action="{{ route('panel.content.media.update', ['media' => $m, 'website' => $website->id]) }}" class="inline-form" style="margin-top:8px">
                            @csrf @method('PUT')
                            <input class="control" type="text" name="alt" value="{{ $m->alt }}" maxlength="190" placeholder="Alt metin" style="flex:1 1 120px">
                            <button type="submit" class="btn btn--ghost btn--pill">Kaydet</button>
                        </form>
                    @endcan
                    @can('content.publish')
                        <form method="POST" action="{{ route('panel.content.media.destroy', ['media' => $m, 'website' => $website->id]) }}" style="margin-top:8px" onsubmit="return confirm('Görsel silinsin mi?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn--ghost btn--pill" style="color:var(--danger);border-color:#E9C4BC">Sil</button>
                        </form>
                    @endcan
                </div>
            @endforeach
        </div>
        <div style="margin-top:16px">{{ $items->links() }}</div>
    @endif
@endsection
