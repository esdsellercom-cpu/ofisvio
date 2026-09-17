@extends('layouts.panel')

@section('title', 'Masalar, ofisler & odalar')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Operasyon · alanlar</p>
            <h1 class="h2">Masalar, ofisler &amp; odalar</h1>
            <p>Tüm lokasyonların rezervasyona konu alanları. Ekleme ve düzenleme lokasyonun oda ekranında; vitrin ve rezervasyon motoru bu kayıtlardan beslenir.</p>
        </div>
        <div class="panel-head__actions">
            @can('geo.view')<a href="{{ route('panel.geo.index') }}" class="btn btn--ghost">Lokasyonlar</a>@endcan
            @can('booking.view')<a href="{{ route('panel.bookings.index') }}" class="btn btn--ghost">Rezervasyonlar</a>@endcan
        </div>
    </div>

    <div class="stack" style="gap:18px">
        <div class="kpis">
            <div class="kpi"><span class="k">Alan</span><span class="v">{{ $counts['total'] }}</span><span class="d">{{ $counts['locations'] }} lokasyonda</span></div>
            <div class="kpi ok"><span class="k">Rezervasyona açık</span><span class="v">{{ $counts['active'] }}</span><span class="d">Aktif alan</span></div>
            <div class="kpi"><span class="k">Toplam kapasite</span><span class="v">{{ $counts['capacity'] }}</span><span class="d">Aktif alanların kişi kapasitesi</span></div>
            <div class="kpi"><span class="k">Türler</span><span class="v">{{ count($kinds) }}</span><span class="d">{{ implode(' · ', $kinds) }}</span></div>
        </div>

        @if ($byLocation->isEmpty())
            <div class="empty-state">Henüz alan tanımlı değil. @can('geo.edit')<a href="{{ route('panel.geo.index') }}" style="color:var(--accent-2);font-weight:600">Bir lokasyon seçip oda ekleyin.</a>@endcan</div>
        @else
            @foreach ($byLocation as $locationName => $rooms)
                @php($location = $rooms->first()->location)
                <div class="card">
                    <div class="card__head">
                        <h3>{{ $locationName }}</h3>
                        <span class="sub">{{ $rooms->count() }} alan · {{ $rooms->where('is_active', true)->count() }} aktif @unless ($location->is_published) · <span class="pill n flat">vitrinde değil</span>@endunless</span>
                        <span class="r">
                            @can('geo.edit')<a href="{{ route('panel.geo.rooms.index', $location) }}" class="btn btn--quiet">Odaları yönet</a>@endcan
                            @can('booking.view')<a href="{{ route('panel.bookings.calendar', $location) }}" class="btn btn--quiet">Takvim</a>@endcan
                        </span>
                    </div>
                    <div class="tw">
                        <table class="t">
                            <thead><tr><th>Alan</th><th>Tür</th><th class="num">Kapasite</th><th>Açık saat</th><th class="num">Ücret</th><th>Durum</th></tr></thead>
                            <tbody>
                                @foreach ($rooms as $room)
                                    <tr>
                                        <td><b>{{ $room->name }}</b>@if ($room->description)<br><span class="mini">{{ Str::limit($room->description, 80) }}</span>@endif</td>
                                        <td><span class="tag">{{ $room->kindLabel() }}</span></td>
                                        <td class="num">{{ $room->capacity }}</td>
                                        <td class="mono small">{{ $room->open_from }}–{{ $room->open_until }} · {{ $room->slot_minutes }} dk</td>
                                        <td class="num">{{ number_format($room->hourly_rate, 0, ',', '.') }} ₺/sa</td>
                                        <td>@if ($room->is_active)<span class="pill g">Aktif</span>@else<span class="pill n">Pasif</span>@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @endif
    </div>
@endsection
