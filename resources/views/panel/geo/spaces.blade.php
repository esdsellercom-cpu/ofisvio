@extends('layouts.panel')

@section('title', 'Masalar & ofisler — '.$location->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.geo.index') }}">GEO</a> · <a href="{{ route('panel.geo.edit', $location) }}">{{ $location->name }}</a></p>
            <h1 class="h2">Masalar &amp; ofisler</h1>
            <p>Aylık tahsis edilen envanter: sabit masa, esnek masa alanı (kapasite = eşzamanlı üye), özel ofis. Saatlik odalar ayrı ekranda. Tahsis işlemleri Alanlar › alan detayından.</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.geo.rooms.index', $location) }}" class="btn btn--ghost">Odalar</a>
            <a href="{{ route('panel.spaces.index') }}" class="btn btn--ghost">Doluluk</a>
        </div>
    </div>

    @error('space')<div class="notice notice--error" role="alert" style="margin-bottom:18px"><span class="notice__dot"></span><div>{{ $message }}</div></div>@enderror

    <div class="stack" style="gap:16px">
        <div class="kpis">
            <div class="kpi"><span class="k">Alan</span><span class="v">{{ $occupancy['total'] }}</span><span class="d">Aktif envanter</span></div>
            <div class="kpi"><span class="k">Doluluk</span><span class="v">%{{ $occupancy['rate'] }}</span><span class="d">{{ $occupancy['occupied'] }} / {{ $occupancy['slots'] }} yer</span></div>
            @foreach ($occupancy['by_kind'] as $row)
                <div class="kpi"><span class="k">{{ $row['label'] }}</span><span class="v">{{ $row['occupied'] }}<span class="mini"> / {{ $row['slots'] }}</span></span><span class="d">{{ $row['total'] }} alan</span></div>
            @endforeach
        </div>

        @foreach ($spaces as $s)
            <form method="POST" action="{{ route('panel.geo.spaces.update', [$location, $s->id]) }}" class="panel stack" style="gap:12px">
                @csrf @method('PUT')
                <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
                    <p class="eyebrow" style="margin:0"><a href="{{ route('panel.spaces.show', $s->id) }}">{{ $s->name }}</a> · {{ $s->kindLabel() }}</p>
                    <span>@if ($s->is_active)<span class="pill {{ $s->isFull() ? 'w' : 'g' }}">{{ $s->isFull() ? 'Dolu' : 'Boş' }} · {{ $s->occupied() }}/{{ $s->slots() }}</span>@else<span class="pill n">Pasif</span>@endif</span>
                </div>
                @include('panel.geo.partials.space-fields', ['s' => $s])
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <button type="submit" class="btn btn--brand">Kaydet</button>
                    <button type="submit" form="delete-space-{{ $s->id }}" class="btn btn--danger" onclick="return confirm('Alan silinsin mi?')">Sil</button>
                </div>
            </form>
            <form id="delete-space-{{ $s->id }}" method="POST" action="{{ route('panel.geo.spaces.destroy', [$location, $s->id]) }}" hidden>@csrf @method('DELETE')</form>
        @endforeach

        <form method="POST" action="{{ route('panel.geo.spaces.store', $location) }}" class="panel stack" style="gap:12px">
            @csrf
            <p class="eyebrow" style="margin:0">Yeni alan</p>
            @include('panel.geo.partials.space-fields', ['s' => null])
            <div><button type="submit" class="btn btn--brand">Ekle</button></div>
        </form>
    </div>
@endsection
