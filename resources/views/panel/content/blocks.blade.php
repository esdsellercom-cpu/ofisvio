@extends('layouts.panel')

@section('title', 'Vitrin blokları')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.content.index') }}">İçerik</a> · {{ $website->name }}</p>
            <h1 class="h2">Vitrin blokları</h1>
        </div>
        <div class="panel-head__actions">
            <a href="{{ $website->baseUrl() }}" class="btn btn--ghost" target="_blank" rel="noopener">Vitrini aç ↗</a>
        </div>
    </div>

    <p class="body-muted" style="margin:0 0 22px;max-width:76ch">
        Ana sayfadaki listeler. Her satır bir kayıt, alanlar <code>|</code> ile ayrılır. Kaydetmek bloğu <strong>hemen canlıya</strong> çıkarır
        (yayın akışı yok; bu yüzden yalnız yayın yetkisi). Kutuyu boşaltıp kaydedince blok kod varsayılanına (config) döner.
    </p>

    @foreach ($blocks as $key => $meta)
        <form method="POST" action="{{ route('panel.content.blocks.update', $key) }}" class="panel stack" style="gap:10px;margin-bottom:18px">
            @csrf @method('PUT')
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:baseline;flex-wrap:wrap">
                <p class="eyebrow" style="margin:0">{{ $meta['label'] }}</p>
                @if (in_array($key, $overridden, true))
                    <span class="badge badge--ok">CMS kaydı</span>
                @else
                    <span class="badge badge--muted">kod varsayılanı</span>
                @endif
            </div>
            <label class="field">
                <span class="label">Satır biçimi: <code>{{ implode(' | ', $meta['fields']) }}</code></span>
                <textarea class="control mono" name="text" style="min-height:{{ 40 + 24 * max(3, substr_count($texts[$key], "\n") + 1) }}px;font-size:13.5px" @error($key) aria-invalid="true" @enderror>{{ old('text', $texts[$key]) }}</textarea>
            </label>
            @error($key)<p class="small" style="color:var(--danger);margin:0">{{ $message }}</p>@enderror
            <div><button type="submit" class="btn btn--brand">Kaydet ve yayınla</button></div>
        </form>
    @endforeach

    <form method="POST" action="{{ route('panel.content.blocks.update', 'pricing_note') }}" class="panel stack" style="gap:10px">
        @csrf @method('PUT')
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:baseline;flex-wrap:wrap">
            <p class="eyebrow" style="margin:0">Fiyat notu</p>
            @if (in_array('pricing_note', $overridden, true))<span class="badge badge--ok">CMS kaydı</span>@else<span class="badge badge--muted">kod varsayılanı</span>@endif
        </div>
        <label class="field"><span class="label">Üyelik tablosunun yanında görünen kısa not</span>
            <textarea class="control" name="text" maxlength="300" style="min-height:64px" @error('pricing_note') aria-invalid="true" @enderror>{{ old('text', $texts['pricing_note']) }}</textarea>
        </label>
        @error('pricing_note')<p class="small" style="color:var(--danger);margin:0">{{ $message }}</p>@enderror
        <div><button type="submit" class="btn btn--brand">Kaydet ve yayınla</button></div>
    </form>
@endsection
