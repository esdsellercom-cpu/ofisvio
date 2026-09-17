@extends('layouts.panel')

@section('title', 'Rezervasyonlar')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Booking v1 · tüm lokasyonlar</p>
            <h1 class="h2">Rezervasyonlar</h1>
        </div>
    </div>

    <div class="grid-auto" style="--min:160px;--gap:14px;margin-bottom:24px">
        <div class="card stat"><span class="stat__value">{{ $counts['today'] }}</span><span class="stat__label">Bugün</span></div>
        <div class="card stat"><span class="stat__value">{{ $counts['upcoming'] }}</span><span class="stat__label">Yaklaşan</span></div>
        <div class="card stat"><span class="stat__value">{{ $counts['rooms'] }}</span><span class="stat__label">Aktif oda</span></div>
    </div>

    <form method="GET" class="inline-form" style="margin-bottom:16px">
        <label class="field" style="flex:1 1 200px"><span class="label">Lokasyon</span>
            <select class="control" name="lokasyon" onchange="this.form.requestSubmit()">
                <option value="">Tümü</option>
                @foreach ($locations as $loc)
                    <option value="{{ $loc->id }}" @selected((string) ($filters['lokasyon'] ?? '') === (string) $loc->id)>{{ $loc->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="field" style="flex:0 1 160px"><span class="label">Durum</span>
            <select class="control" name="durum" onchange="this.form.requestSubmit()">
                <option value="">Tümü</option>
                @foreach (\App\Models\Booking::STATUSES as $k => $label)
                    <option value="{{ $k }}" @selected(($filters['durum'] ?? '') === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="field" style="flex:0 1 170px"><span class="label">Başlangıç ≥</span>
            <input class="control" type="date" name="baslangic" value="{{ $filters['baslangic'] ?? '' }}" onchange="this.form.requestSubmit()">
        </label>
        <noscript><button type="submit" class="btn btn--ghost">Süz</button></noscript>
    </form>

    <div class="panel" style="margin-bottom:20px">
        <p class="eyebrow">Lokasyon masaları</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            @foreach ($locations as $loc)
                @can('booking.view', $loc)
                    <a href="{{ route('panel.bookings.location', $loc) }}" class="chip">{{ $loc->name }}</a>
                @endcan
            @endforeach
        </div>
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Tarih</th><th>Lokasyon · oda</th><th>Şirket</th><th class="num">Süre</th><th class="num">Tutar</th><th>Durum</th><th>Açan</th></tr></thead>
            <tbody>
                @forelse ($rows as $b)
                    <tr>
                        <td class="mono small">{{ $b->starts_at->format('d.m.Y H:i') }}–{{ $b->ends_at->format('H:i') }}</td>
                        <td>{{ $b->location->name }} · {{ $b->room->name }}</td>
                        <td>{{ $b->company?->legal_name }}</td>
                        <td class="num mono">{{ rtrim(rtrim(number_format($b->hours(), 2, ',', ''), '0'), ',') }} sa</td>
                        <td class="num mono">{{ number_format($b->total_amount, 0, ',', '.') }} ₺</td>
                        <td><span class="badge badge--{{ $b->isActive() ? 'ok' : 'warn' }}">{{ $b->statusLabel() }}</span>@if ($b->overridden) <span class="badge badge--warn" title="Kural dışı (JIT)">JIT</span>@endif</td>
                        <td class="small">{{ $b->booker?->name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">Süzgeçle eşleşen rezervasyon yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:16px">{{ $rows->links() }}</div>
@endsection
