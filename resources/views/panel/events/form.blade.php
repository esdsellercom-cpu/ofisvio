@extends('layouts.panel')

@section('title', $event ? 'Etkinliği düzenle' : 'Yeni etkinlik')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.events.index') }}">Etkinlikler</a> / {{ $event ? $event->title : 'yeni' }}</p>
            <h1 class="h2">{{ $event ? 'Etkinliği düzenle' : 'Yeni etkinlik' }}</h1>
            @if ($event)<p>Bağlantı sabit: <code class="mono">{{ $event->path() }}</code></p>@endif
        </div>
    </div>

    <div class="card" style="max-width:820px">
        <div class="card__body">
            <form method="POST" action="{{ $event ? route('panel.events.update', $event) : route('panel.events.store') }}" class="stack" style="gap:12px">
                @csrf
                @if ($event) @method('PUT') @endif
                <label class="field"><span class="label">Başlık</span><input class="control" type="text" name="title" value="{{ old('title', $event?->title) }}" required maxlength="120"></label>
                <label class="field"><span class="label">Özet (liste kartı)</span><input class="control" type="text" name="summary" value="{{ old('summary', $event?->summary) }}" maxlength="300"></label>
                <div class="grid g2">
                    <label class="field"><span class="label">Başlangıç</span><input class="control" type="datetime-local" name="starts_at" value="{{ old('starts_at', $event?->starts_at?->format('Y-m-d\TH:i')) }}" required></label>
                    <label class="field"><span class="label">Bitiş</span><input class="control" type="datetime-local" name="ends_at" value="{{ old('ends_at', $event?->ends_at?->format('Y-m-d\TH:i')) }}" required @error('ends_at') aria-invalid="true" @enderror>@error('ends_at')<span class="field-error">{{ $message }}</span>@enderror</label>
                    <label class="field"><span class="label">Lokasyon</span>
                        <select class="control" name="location_id"><option value="">Çevrimiçi / belirtilmedi</option>@foreach ($locations as $l)<option value="{{ $l->id }}" @selected((int) old('location_id', $event?->location_id) === $l->id)>{{ $l->name }}</option>@endforeach</select>
                    </label>
                    <label class="field"><span class="label">Alan (oda)</span>
                        <select class="control" name="room_id"><option value="">—</option>@foreach ($rooms as $r)<option value="{{ $r->id }}" @selected((int) old('room_id', $event?->room_id) === $r->id)>{{ $r->location->name }} · {{ $r->name }}</option>@endforeach</select>
                    </label>
                    <label class="field"><span class="label">Kontenjan (boş = sınırsız)</span><input class="control" type="number" name="capacity" value="{{ old('capacity', $event?->capacity) }}" min="1" max="5000"></label>
                    <label class="field"><span class="label">Ücret (₺, 0 = ücretsiz)</span><input class="control" type="number" name="price" value="{{ old('price', $event?->price ?? 0) }}" min="0"></label>
                </div>
                <label class="field"><span class="label">Kapak görseli (medya kütüphanesi)</span>
                    <select class="control" name="cover_media_id"><option value="">— yok —</option>@foreach ($mediaOptions as $m)<option value="{{ $m->id }}" @selected((string) old('cover_media_id', $event?->cover_media_id) === (string) $m->id)>{{ $m->original_name }} ({{ $m->width }}×{{ $m->height }})</option>@endforeach</select>
                </label>
                <label class="field"><span class="label">Açıklama (Markdown)</span><textarea class="control mono" name="description" style="min-height:200px">{{ old('description', $event?->description) }}</textarea></label>
                <label class="checkbox-row"><input type="checkbox" name="is_published" value="1" @checked(old('is_published', $event?->is_published ?? false))><span>Yayında (vitrinde listelenir)</span></label>
                <label class="checkbox-row"><input type="checkbox" name="registration_open" value="1" @checked(old('registration_open', $event?->registration_open ?? true))><span>Kayıt açık</span></label>
                <div><button type="submit" class="btn btn--brand">Kaydet</button> <a href="{{ route('panel.events.index') }}" class="btn btn--ghost">Vazgeç</a></div>
            </form>
        </div>
    </div>
@endsection
