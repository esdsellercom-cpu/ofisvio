@extends('layouts.panel')

@section('title', 'GEO — '.$location->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.geo.index') }}">GEO</a> · {{ $location->city }}</p>
            <h1 class="h2">{{ $location->name }}</h1>
        </div>
    </div>

    <form method="POST" action="{{ route('panel.geo.update', $location) }}" class="grid-auto" style="--min:320px;--gap:20px;align-items:start">
        @csrf @method('PUT')

        <div class="panel stack" style="gap:14px">
            <p class="eyebrow" style="margin:0">Adres ve konum</p>
            <p class="small muted" style="margin:0">Adres satırı ({{ $location->address_line }}) lokasyon seed'inden gelir; burada varlık alanları düzenlenir.</p>
            <div class="grid-auto" style="--min:140px;--gap:12px">
                <label class="field"><span class="label">Enlem</span>
                    <input class="control mono" type="text" name="latitude" value="{{ old('latitude', $location->latitude) }}" inputmode="decimal" placeholder="41.0621" @error('latitude') aria-invalid="true" @enderror>
                </label>
                <label class="field"><span class="label">Boylam</span>
                    <input class="control mono" type="text" name="longitude" value="{{ old('longitude', $location->longitude) }}" inputmode="decimal" placeholder="29.0073" @error('longitude') aria-invalid="true" @enderror>
                </label>
            </div>
            <div class="grid-auto" style="--min:140px;--gap:12px">
                <label class="field"><span class="label">İlçe</span>
                    <input class="control" type="text" name="district" value="{{ old('district', $location->district) }}" maxlength="64" placeholder="Şişli">
                </label>
                <label class="field"><span class="label">Posta kodu</span>
                    <input class="control mono" type="text" name="postal_code" value="{{ old('postal_code', $location->postal_code) }}" maxlength="16">
                </label>
            </div>
            <label class="field"><span class="label">Telefon</span>
                <input class="control mono" type="tel" name="phone" value="{{ old('phone', $location->phone) }}" maxlength="32" placeholder="0850 840 00 00">
            </label>
            <label class="field"><span class="label">Çalışma saatleri — satır başına, schema.org biçimi</span>
                <textarea class="control mono" name="opening_hours" style="min-height:80px" placeholder="Mo-Fr 08:30-19:00&#10;Sa 09:00-14:00">{{ old('opening_hours', implode("\n", $location->opening_hours ?? [])) }}</textarea>
            </label>
        </div>

        <div class="stack" style="gap:20px">
            <div class="panel stack" style="gap:14px">
                <p class="eyebrow" style="margin:0">Sayfa metni</p>
                <label class="field"><span class="label">Açıklama (Markdown)</span>
                    <textarea class="control mono" name="geo_description" style="min-height:260px;font-size:14px;line-height:1.6">{{ old('geo_description', $location->geo_description) }}</textarea>
                </label>
                <label class="field"><span class="label">Meta açıklama (≤ 160)</span>
                    <textarea class="control" name="geo_meta_description" maxlength="160" style="min-height:64px">{{ old('geo_meta_description', $location->geo_meta_description) }}</textarea>
                </label>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button type="submit" class="btn btn--brand">Kaydet</button>
                <a href="{{ route('panel.geo.index') }}" class="btn btn--ghost">Vazgeç</a>
            </div>
        </div>
    </form>
@endsection
