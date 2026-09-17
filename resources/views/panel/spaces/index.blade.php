@extends('layouts.panel')

@section('title', 'Masalar, ofisler & odalar')

@php($query = array_filter(['sekme' => $tab, 'durum' => $status, 'lokasyon' => $locationId, 'q' => $q !== '' ? $q : null]))

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Operasyon · envanter</p>
            <h1 class="h2">Masalar, ofisler &amp; odalar</h1>
            <p>Envanteri buradan ekleyin, lokasyona bağlayın, üyeye tahsis edin, demirbaş tanımlayın; durum ve kime tahsisli olduğu kartta. Saatlik odalar rezervasyon takvimiyle çalışır.</p>
        </div>
        <div class="panel-head__actions">
            @if ($canManage)
                <button type="button" class="btn btn--brand" data-modal-open="#modal-inventory" data-title="Envanter ekle">+ Envanter ekle</button>
                <button type="button" class="btn btn--ghost" data-modal-open="#modal-assign" data-title="Hızlı tahsis">+ Hızlı tahsis</button>
                <button type="button" class="btn btn--ghost" data-modal-open="#modal-asset" data-title="Demirbaş ekle">+ Demirbaş ekle</button>
            @elseif ($canRooms)
                <button type="button" class="btn btn--brand" data-modal-open="#modal-inventory" data-title="Oda ekle">+ Oda ekle</button>
            @endif
        </div>
    </div>

    <div class="stack" style="gap:16px">
        @if ($scoped)<div class="note">Yalnız yetkili olduğunuz lokasyon(lar) listeleniyor.</div>@endif
        @error('assignment')<div class="notice notice--error" role="alert"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror
        @error('asset')<div class="notice notice--error" role="alert"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror

        <div class="kpis">
            <div class="kpi {{ $occupancy['rate'] >= 90 ? 'ok' : ($occupancy['total'] > 0 && $occupancy['rate'] < 50 ? 'watch' : '') }}"><span class="k">Doluluk</span><span class="v">%{{ $occupancy['rate'] }}</span><span class="d">{{ $occupancy['occupied'] }} / {{ $occupancy['slots'] }} yer tahsisli</span></div>
            <div class="kpi"><span class="k">Envanter</span><span class="v">{{ $counts['tum'] }}</span><span class="d">{{ $counts['masalar'] }} masa · {{ $counts['ofisler'] }} ofis · {{ $counts['odalar'] }} oda</span></div>
            <div class="kpi"><span class="k">Demirbaş</span><span class="v">{{ $counts['demirbas'] }}</span><span class="d">Lokasyonlara kayıtlı</span></div>
            <div class="kpi {{ $occupancy['ending_30d'] > 0 ? 'watch' : '' }}"><span class="k">30 günde biten tahsis</span><span class="v">{{ $occupancy['ending_30d'] }}</span><span class="d">{{ $counts['tahsisler'] }} aktif tahsis</span></div>
        </div>

        <nav class="tabbar" aria-label="Envanter sekmeleri">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('panel.spaces.index', array_filter(['sekme' => $key, 'lokasyon' => $locationId])) }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }} <span class="small muted">{{ $counts[$key] }}</span></a>
            @endforeach
        </nav>

        <form method="GET" class="inv-toolbar">
            <input type="hidden" name="sekme" value="{{ $tab }}">
            <select class="control" name="lokasyon" style="max-width:220px" onchange="this.form.requestSubmit()">
                <option value="">Tüm lokasyonlar</option>
                @foreach ($locations as $loc)<option value="{{ $loc->id }}" @selected($locationId === $loc->id)>{{ $loc->name }}</option>@endforeach
            </select>
            @if ($tab !== 'tahsisler')
                <span style="display:flex;gap:4px;flex-wrap:wrap">
                    @foreach (['' => 'Hepsi', 'musait' => 'Müsait', 'tahsisli' => 'Tahsisli', 'bakimda' => 'Bakımda', 'pasif' => 'Pasif'] as $k => $label)
                        <a href="{{ route('panel.spaces.index', array_filter(['sekme' => $tab, 'lokasyon' => $locationId, 'durum' => $k, 'q' => $q ?: null])) }}" class="btn btn--ghost btn--pill {{ ($status ?? '') === $k ? 'is-active' : '' }}" style="padding:5px 11px;font-size:12px">{{ $label }}</a>
                    @endforeach
                </span>
            @endif
            <span class="spacer"></span>
            @if ($tab !== 'tahsisler')
                @if ($status)<input type="hidden" name="durum" value="{{ $status }}">@endif
                <input class="control" type="search" name="q" value="{{ $q }}" placeholder="Ad, kod, üye, şirket…" style="max-width:240px">
                <button type="submit" class="btn btn--ghost">Ara</button>
            @endif
        </form>

        {{-- Envanter kartları --}}
        @if (in_array($tab, ['tum', 'masalar', 'ofisler', 'odalar'], true))
            @if ($spaces->isEmpty() && $rooms->isEmpty())
                <div class="empty-state">Bu süzgeçte envanter yok.@if ($canManage) <button type="button" class="btn btn--quiet" data-modal-open="#modal-inventory" data-title="Envanter ekle">+ Envanter ekle</button>@endif</div>
            @else
                <div class="inv-grid">
                    @foreach ($spaces as $s)
                        @include('panel.spaces.partials.card-space', ['s' => $s])
                    @endforeach
                    @foreach ($rooms as $r)
                        @include('panel.spaces.partials.card-room', ['r' => $r])
                    @endforeach
                </div>
            @endif
        @endif

        {{-- Demirbaşlar --}}
        @if ($tab === 'demirbas')
            <div class="card">
                <div class="card__head"><h3>Demirbaşlar</h3><span class="sub">{{ $assets->count() }} kayıt</span></div>
                @if ($assets->isEmpty())
                    <div class="empty-state" style="border:0">Demirbaş yok.@if ($canManage) <button type="button" class="btn btn--quiet" data-modal-open="#modal-asset" data-title="Demirbaş ekle">+ Demirbaş ekle</button>@endif</div>
                @else
                    <div class="tw"><table class="t">
                        <thead><tr><th>Demirbaş</th><th>Kategori</th><th>Seri no</th><th>Lokasyon / alan</th><th>Tahsis</th><th>Durum</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($assets as $a)
                                <tr>
                                    <td><b>{{ $a->name }}</b>@if ($a->code) <span class="mono small muted">{{ $a->code }}</span>@endif @if ($a->notes)<br><span class="mini">{{ $a->notes }}</span>@endif</td>
                                    <td><span class="tag">{{ $a->categoryLabel() }}</span></td>
                                    <td class="mono small">{{ $a->serial ?? '—' }}</td>
                                    <td class="small">{{ $a->location->name }}@if ($a->space) · {{ $a->space->name }}@endif</td>
                                    <td class="small">@if ($a->assignment)<a href="{{ route('panel.spaces.show', $a->assignment->space_id) }}">{{ $a->assignment->user?->name ?? $a->assignment->company?->legal_name ?? '—' }}</a>@else —@endif</td>
                                    <td><span class="pill {{ match ($a->status) {'available' => 'g', 'assigned' => 'a', 'maintenance' => 'w', default => 'n'} }}">{{ $a->statusLabel() }}</span></td>
                                    <td class="num">
                                        @if ($canManage)
                                            <span style="display:inline-flex;gap:4px">
                                                <button type="button" class="btn btn--quiet" data-modal-open="#modal-asset" data-title="Demirbaşı düzenle" data-method="PUT" data-action="{{ route('panel.spaces.inventory.asset.update', $a->id) }}" data-fill="{{ json_encode(['location_id' => $a->location_id, 'space_id' => $a->space_id, 'name' => $a->name, 'code' => $a->code, 'category' => $a->category, 'serial' => $a->serial, 'status' => $a->status === 'assigned' ? 'available' : $a->status, 'notes' => $a->notes]) }}">Düzenle</button>
                                                @if ($a->status !== 'assigned')
                                                    <form method="POST" action="{{ route('panel.spaces.inventory.asset.destroy', $a->id) }}" onsubmit="return confirm('Demirbaş silinsin mi?')">@csrf @method('DELETE')<input type="hidden" name="_tab" value="demirbas"><button type="submit" class="btn btn--quiet" style="color:var(--crit)">Sil</button></form>
                                                @endif
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
            </div>
        @endif

        {{-- Tahsisler --}}
        @if ($tab === 'tahsisler')
            <div class="card">
                <div class="card__head"><h3>Aktif tahsisler</h3><span class="sub">{{ $assignments->count() }} kayıt · kime, nereye, ne zamana kadar</span></div>
                @if ($assignments->isEmpty())
                    <div class="empty-state" style="border:0">Aktif tahsis yok.@if ($canManage) <button type="button" class="btn btn--quiet" data-modal-open="#modal-assign" data-title="Hızlı tahsis">+ Hızlı tahsis</button>@endif</div>
                @else
                    <div class="tw"><table class="t">
                        <thead><tr><th>Envanter</th><th>Şirket / üye</th><th>Üyelik</th><th>Başlangıç</th><th>Bitiş</th><th class="num">Demirbaş</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($assignments as $as)
                                <tr>
                                    <td><a href="{{ route('panel.spaces.show', $as->space_id) }}"><b>{{ $as->space->name }}</b></a><span class="mini" style="display:block">{{ $as->space->kindLabel() }} · {{ $as->space->location->name }}</span></td>
                                    <td>{{ $as->company?->legal_name ?? '—' }}@if ($as->user)<span class="mini" style="display:block">{{ $as->user->name }}</span>@endif</td>
                                    <td class="small">{{ $as->subscription?->plan?->name ?? '—' }}</td>
                                    <td class="mono small">{{ $as->starts_on?->format('d.m.Y') }}</td>
                                    <td class="mono small">{{ $as->ends_on?->format('d.m.Y') ?? 'süresiz' }}@if ($as->ends_on && $as->ends_on->lte(now()->addDays(30))) <span class="pill w flat">yakında</span>@endif</td>
                                    <td class="num">{{ $as->assets_count }}</td>
                                    <td class="num">
                                        @if ($canManage)
                                            <span style="display:inline-flex;gap:4px">
                                                <button type="button" class="btn btn--quiet" data-modal-open="#modal-assignment" data-title="Tahsisi düzenle · {{ $as->space->name }}" data-method="PUT" data-action="{{ route('panel.spaces.inventory.assignment.update', $as->id) }}" data-fill="{{ json_encode(['company_id' => $as->company_id, 'space_location' => $as->space->location_id, 'ends_on' => $as->ends_on?->toDateString(), 'user_id' => $as->user_id, 'note' => $as->note, 'asset_ids' => $as->assets->pluck('id')->all()]) }}">Değiştir</button>
                                                <form method="POST" action="{{ route('panel.spaces.inventory.assignment.end', $as->id) }}" onsubmit="return confirm('Tahsis sonlandırılsın mı? Demirbaşlar serbest kalır.')">@csrf<input type="hidden" name="_tab" value="tahsisler"><button type="submit" class="btn btn--quiet" style="color:var(--crit)">Sonlandır</button></form>
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
            </div>
        @endif
    </div>

    @if ($canManage || $canRooms)
        @include('panel.spaces.partials.modal-inventory')
    @endif
    @if ($canManage)
        @include('panel.spaces.partials.modal-assign')
        @include('panel.spaces.partials.modal-assignment')
        @include('panel.spaces.partials.modal-asset')
    @endif
@endsection
