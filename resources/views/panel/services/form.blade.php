@extends('layouts.panel')

@section('title', $service ? 'Hizmet — '.$service->name : 'Yeni hizmet')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.services.index') }}">Hizmetler</a></p>
            <h1 class="h2">{{ $service ? $service->name : 'Yeni hizmet' }}</h1>
        </div>
    </div>

    <form method="POST" action="{{ $service ? route('panel.services.update', $service) : route('panel.services.store') }}" class="panel stack" style="gap:14px;max-width:820px">
        @csrf @if ($service) @method('PUT') @endif
        @error('name')<div class="notice notice--error" role="alert"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror
        <div class="grid-auto" style="--min:220px;--gap:12px">
            <label class="field"><span class="label">Ad</span><input class="control" type="text" name="name" value="{{ old('name', $service?->name) }}" required minlength="2" maxlength="80"></label>
            <label class="field"><span class="label">Fiyat metni (boş: gösterilmez)</span><input class="control mono" type="text" name="price_text" value="{{ old('price_text', $service?->price_text) }}" maxlength="60" placeholder="₺790/ay'dan"></label>
            <label class="field"><span class="label">Rezervasyon türü (odalarla bağ)</span>
                <select class="control" name="booking_kind"><option value="">— rezervasyonsuz —</option>@foreach ($kinds as $k => $l)<option value="{{ $k }}" @selected(old('booking_kind', $service?->booking_kind) === $k)>{{ $l }}</option>@endforeach</select>
            </label>
            <label class="field"><span class="label">Sıra</span><input class="control mono" type="number" name="sort_order" value="{{ old('sort_order', $service?->sort_order ?? 0) }}" min="0" max="999"></label>
        </div>
        <label class="field"><span class="label">Özet (kart metni)</span><textarea class="control" name="summary" maxlength="300" style="min-height:70px">{{ old('summary', $service?->summary) }}</textarea></label>
        <label class="field"><span class="label">Açıklama (Markdown, hizmet sayfası)</span><textarea class="control mono" name="description" style="min-height:200px;font-size:14px">{{ old('description', $service?->description) }}</textarea></label>
        <label class="field"><span class="label">Kapak görseli (medya kütüphanesi)</span>
            <select class="control" name="cover_media_id"><option value="">— yok —</option>@foreach ($mediaOptions as $m)<option value="{{ $m->id }}" @selected((string) old('cover_media_id', $service?->cover_media_id) === (string) $m->id)>{{ $m->original_name }} ({{ $m->width }}×{{ $m->height }})</option>@endforeach</select>
        </label>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
            <label class="checkbox-row"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $service?->is_active ?? true))><span>Aktif (vitrinde)</span></label>
            <label class="checkbox-row"><input type="checkbox" name="is_flagship" value="1" @checked(old('is_flagship', $service?->is_flagship ?? false))><span>Amiral ürün</span></label>
        </div>
        @if ($service)
            <p class="small muted" style="margin:0">Sunan lokasyonlar: {{ $service->locations->pluck('name')->implode(', ') ?: '—' }} — bağ, lokasyon künyesinden yönetilir.</p>
        @endif
        <div style="display:flex;gap:10px"><button type="submit" class="btn btn--brand">Kaydet</button><a href="{{ route('panel.services.index') }}" class="btn btn--ghost">Vazgeç</a></div>
    </form>
@endsection