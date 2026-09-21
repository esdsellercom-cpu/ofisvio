@extends('layouts.panel')

@section('title', 'Odalar — '.$location->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.geo.index') }}">GEO</a> · <a href="{{ route('panel.geo.edit', $location) }}">{{ $location->name }}</a></p>
            <h1 class="h2">Odalar</h1>
        </div>
        <div class="panel-head__actions">
            @can('booking.view', $location)
                <a href="{{ route('panel.bookings.location', $location) }}" class="btn btn--ghost">Rezervasyon masası</a>
            @endcan
        </div>
    </div>

    @error('room')<div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror

    <div class="stack" style="gap:16px">
        @foreach ($rooms as $r)
            <form method="POST" action="{{ route('panel.geo.rooms.update', [$location, $r->id]) }}" class="panel stack" style="gap:12px">
                @csrf @method('PUT')
                <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
                    <p class="eyebrow" style="margin:0">{{ $r->name }} · {{ $r->kindLabel() }}</p>
                    <span class="badge badge--{{ match ($r->operationalStatus()) {'active' => 'ok', 'maintenance' => 'warn', default => 'muted'} }}">{{ $r->operationalStatus() === 'active' ? 'Rezervasyona açık' : $r->operationalLabel() }}@if ($r->isUnderMaintenance()) · {{ $r->maintenance_until?->format('d.m.Y') }}@endif</span>
                </div>
                @include('panel.geo.partials.room-fields', ['r' => $r])
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <button type="submit" class="btn btn--brand">Kaydet</button>
                    <button type="submit" form="delete-room-{{ $r->id }}" class="btn btn--ghost" style="color:var(--danger);border-color:#E9C4BC" data-confirm="Oda silinsin mi?">Sil</button>
                </div>
            </form>
            <form id="delete-room-{{ $r->id }}" method="POST" action="{{ route('panel.geo.rooms.destroy', [$location, $r->id]) }}" hidden>@csrf @method('DELETE')</form>
        @endforeach

        <form method="POST" action="{{ route('panel.geo.rooms.store', $location) }}" class="panel stack" style="gap:12px">
            @csrf
            <p class="eyebrow" style="margin:0">Yeni oda</p>
            @include('panel.geo.partials.room-fields', ['r' => null])
            <div><button type="submit" class="btn btn--brand">Oda ekle</button></div>
        </form>
    </div>
@endsection
