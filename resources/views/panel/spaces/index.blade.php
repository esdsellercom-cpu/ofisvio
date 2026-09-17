@extends('layouts.panel')

@section('title', 'Masalar, ofisler & odalar')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Operasyon · alanlar</p>
            <h1 class="h2">Masalar, ofisler &amp; odalar</h1>
            <p>Aylık tahsis edilen masa/ofis envanteri ve doluluğu; saatlik odalar ayrı sekmede. Envanter lokasyon künyesinden düzenlenir, tahsis alan detayından yapılır.</p>
        </div>
        <div class="panel-head__actions">
            @can('geo.view')<a href="{{ route('panel.geo.index') }}" class="btn btn--ghost">Lokasyonlar</a>@endcan
            @can('booking.view')<a href="{{ route('panel.bookings.index') }}" class="btn btn--ghost">Rezervasyonlar</a>@endcan
        </div>
    </div>

    <div class="stack" style="gap:18px">
        <nav class="tabbar" aria-label="Alan sekmeleri">
            <a href="{{ route('panel.spaces.index') }}" @if ($tab === 'alanlar') aria-current="page" @endif>Masalar &amp; ofisler</a>
            <a href="{{ route('panel.spaces.index', ['sekme' => 'odalar']) }}" @if ($tab === 'odalar') aria-current="page" @endif>Odalar (saatlik)</a>
        </nav>

        @if ($tab === 'alanlar')
            <div class="kpis">
                <div class="kpi {{ $occupancy['rate'] >= 90 ? 'ok' : ($occupancy['total'] > 0 && $occupancy['rate'] < 50 ? 'watch' : '') }}"><span class="k">Doluluk</span><span class="v">%{{ $occupancy['rate'] }}</span><span class="d">{{ $occupancy['occupied'] }} / {{ $occupancy['slots'] }} yer tahsisli</span></div>
                @foreach ($occupancy['by_kind'] as $row)
                    <div class="kpi"><span class="k">{{ $row['label'] }}</span><span class="v">{{ $row['occupied'] }}<span class="mini"> / {{ $row['slots'] }}</span></span><span class="d">{{ $row['total'] }} alan</span></div>
                @endforeach
                <div class="kpi {{ $occupancy['ending_30d'] > 0 ? 'watch' : '' }}"><span class="k">30 günde biten tahsis</span><span class="v">{{ $occupancy['ending_30d'] }}</span><span class="d">Yenileme ya da boşaltma</span></div>
            </div>

            @if ($byLocation === [])
                <div class="empty-state">Henüz masa/ofis tanımlı değil. @can('geo.edit')<a href="{{ route('panel.geo.index') }}" style="color:var(--accent-2);font-weight:600">Bir lokasyon seçip envanter ekleyin.</a>@endcan</div>
            @else
                @foreach ($byLocation as $row)
                    @php($location = $row['location'])
                    @php($o = $row['occupancy'])
                    <div class="card">
                        <div class="card__head">
                            <h3>{{ $location->name }}</h3>
                            <span class="sub">{{ $o['total'] }} alan · %{{ $o['rate'] }} doluluk · {{ $o['occupied'] }}/{{ $o['slots'] }} yer</span>
                            <span class="r">
                                <span class="meter" style="width:120px;flex:none"><i class="{{ $o['rate'] >= 90 ? 'g' : ($o['rate'] < 50 ? 'w' : '') }}" style="width:{{ min(100, $o['rate']) }}%"></i></span>
                                @can('geo.edit')<a href="{{ route('panel.geo.spaces.index', $location) }}" class="btn btn--quiet">Envanteri yönet</a>@endcan
                            </span>
                        </div>
                        <div class="tw">
                            <table class="t">
                                <thead><tr><th>Alan</th><th>Tür</th><th>Kat / bölge</th><th class="num">Yer</th><th class="num">Aylık</th><th>Durum</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($spaces[$location->id] ?? [] as $s)
                                        <tr>
                                            <td><b>{{ $s->name }}</b>@if ($s->notes)<br><span class="mini">{{ $s->notes }}</span>@endif</td>
                                            <td><span class="tag">{{ $s->kindLabel() }}</span></td>
                                            <td class="small">{{ $s->floor ? 'Kat '.$s->floor : '—' }}{{ $s->zone ? ' · '.$s->zone : '' }}</td>
                                            <td class="num">{{ $s->occupied() }} / {{ $s->slots() }}</td>
                                            <td class="num">{{ money($s->monthly_price) }}</td>
                                            <td>@if (! $s->is_active)<span class="pill n">Pasif</span>@elseif ($s->isFull())<span class="pill w">Dolu</span>@else<span class="pill g">Boş</span>@endif</td>
                                            <td class="num"><a href="{{ route('panel.spaces.show', $s->id) }}" class="btn btn--quiet">Aç</a></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            @endif
        @else
            <div class="kpis">
                <div class="kpi"><span class="k">Oda</span><span class="v">{{ $roomCounts['total'] }}</span><span class="d">{{ $roomCounts['locations'] }} lokasyonda</span></div>
                <div class="kpi ok"><span class="k">Rezervasyona açık</span><span class="v">{{ $roomCounts['active'] }}</span><span class="d">Aktif oda</span></div>
                <div class="kpi"><span class="k">Toplam kapasite</span><span class="v">{{ $roomCounts['capacity'] }}</span><span class="d">Aktif odaların kişi kapasitesi</span></div>
                <div class="kpi"><span class="k">Türler</span><span class="v">{{ count($kinds) }}</span><span class="d">{{ implode(' · ', $kinds) }}</span></div>
            </div>

            @if ($roomsByLocation->isEmpty())
                <div class="empty-state">Henüz oda tanımlı değil. @can('geo.edit')<a href="{{ route('panel.geo.index') }}" style="color:var(--accent-2);font-weight:600">Bir lokasyon seçip oda ekleyin.</a>@endcan</div>
            @else
                @foreach ($roomsByLocation as $locationName => $rooms)
                    @php($location = $rooms->first()->location)
                    <div class="card">
                        <div class="card__head">
                            <h3>{{ $locationName }}</h3>
                            <span class="sub">{{ $rooms->count() }} oda · {{ $rooms->where('is_active', true)->count() }} aktif @unless ($location->is_published) · <span class="pill n flat">vitrinde değil</span>@endunless</span>
                            <span class="r">
                                @can('geo.edit')<a href="{{ route('panel.geo.rooms.index', $location) }}" class="btn btn--quiet">Odaları yönet</a>@endcan
                                @can('booking.view')<a href="{{ route('panel.bookings.calendar', $location) }}" class="btn btn--quiet">Takvim</a>@endcan
                            </span>
                        </div>
                        <div class="tw">
                            <table class="t">
                                <thead><tr><th>Oda</th><th>Tür</th><th class="num">Kapasite</th><th>Açık saat</th><th class="num">Ücret</th><th>Durum</th></tr></thead>
                                <tbody>
                                    @foreach ($rooms as $room)
                                        <tr>
                                            <td><b>{{ $room->name }}</b>@if ($room->description)<br><span class="mini">{{ Str::limit($room->description, 80) }}</span>@endif</td>
                                            <td><span class="tag">{{ $room->kindLabel() }}</span></td>
                                            <td class="num">{{ $room->capacity }}</td>
                                            <td class="mono small">{{ $room->open_from }}–{{ $room->open_until }} · {{ $room->slot_minutes }} dk</td>
                                            <td class="num">{{ money($room->hourly_rate) }}/sa</td>
                                            <td>@if ($room->is_active)<span class="pill g">Aktif</span>@else<span class="pill n">Pasif</span>@endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            @endif
        @endif
    </div>
@endsection
