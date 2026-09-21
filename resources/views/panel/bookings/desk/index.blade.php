@extends('layouts.panel')

@section('title', 'Rezervasyonlar')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Booking engine · tüm lokasyonlar</p>
            <h1 class="h2">Rezervasyonlar</h1>
        </div>
        <div class="panel-head__actions">
            @can('settings.view')<a href="{{ route('panel.settings.index') }}" class="btn btn--ghost">Onay politikası</a>@endcan
        </div>
    </div>

    {{-- Dashboard (§20): gerçek toplamlar --}}
    <div class="grid-auto" style="--min:150px;--gap:14px;margin-bottom:24px">
        <div class="card stat"><span class="stat__value">{{ $stats['today'] }}</span><span class="stat__label">Bugün</span></div>
        <div class="card stat"><span class="stat__value" style="color:{{ $stats['pending'] > 0 ? 'var(--warn)' : 'inherit' }}">{{ $stats['pending'] }}</span><span class="stat__label">Onay bekleyen</span></div>
        <div class="card stat"><span class="stat__value">{{ $stats['upcoming'] }}</span><span class="stat__label">Yaklaşan</span></div>
        <div class="card stat"><span class="stat__value mono" style="font-size:22px">%{{ $stats['occupancy_today'] }}</span><span class="stat__label">Bugünkü doluluk</span></div>
        <div class="card stat"><span class="stat__value mono" style="font-size:22px">%{{ $stats['cancel_rate_30d'] }}</span><span class="stat__label">İptal oranı (30g)</span></div>
        <div class="card stat"><span class="stat__value">{{ $stats['no_show_30d'] }}</span><span class="stat__label">Gelmedi (30g)</span></div>
        <div class="card stat"><span class="stat__value mono" style="font-size:22px">{{ money($stats['revenue_30d']) }}</span><span class="stat__label">Onaylı tutar (30g)</span></div>
        <div class="card stat"><span class="stat__value mono" style="font-size:22px">{{ $stats['avg_hours_30d'] }} sa</span><span class="stat__label">Ort. süre (30g)</span></div>
    </div>

    <nav style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
        @foreach ($tabs as $k => $label)
            <a href="{{ route('panel.bookings.index', array_filter(['sekme' => $k, 'lokasyon' => $filters['lokasyon'] ?? null])) }}" class="chip" aria-pressed="{{ $tab === $k ? 'true' : 'false' }}">{{ $label }}@isset($tabCounts[$k]) <span class="mono">{{ $tabCounts[$k] }}</span>@endisset</a>
        @endforeach
    </nav>

    <form method="GET" class="inline-form" style="margin-bottom:16px">
        <input type="hidden" name="sekme" value="{{ $tab }}">
        <label class="field" style="flex:1 1 200px"><span class="label">Lokasyon</span>
            <select class="control" name="lokasyon" data-autosubmit>
                <option value="">Tümü</option>
                @foreach ($locations as $loc)
                    <option value="{{ $loc->id }}" @selected((string) ($filters['lokasyon'] ?? '') === (string) $loc->id)>{{ $loc->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="field" style="flex:0 1 170px"><span class="label">Başlangıç ≥</span>
            <input class="control" type="date" name="baslangic" value="{{ $filters['baslangic'] ?? '' }}" data-autosubmit>
        </label>
        <label class="field" style="flex:1 1 200px"><span class="label">Ara (no, ad, e-posta, telefon, firma)</span>
            <input class="control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="80">
        </label>
        <button type="submit" class="btn btn--ghost">Süz</button>
    </form>

    <div class="panel" style="margin-bottom:20px">
        <p class="eyebrow">Lokasyon masaları</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            @foreach ($locations as $loc)
                @can('booking.view', $loc)
                    <a href="{{ route('panel.bookings.location', $loc) }}" class="chip">{{ $loc->name }}</a>
                    <a href="{{ route('panel.bookings.calendar', $loc) }}" class="chip" title="Takvim">{{ $loc->name }} · takvim</a>
                @endcan
            @endforeach
        </div>
        @if ($stats['by_location'] !== [])
            <p class="small muted" style="margin:10px 0 0">Son 30 gün: @foreach ($stats['by_location'] as $row){{ $row['name'] }} {{ $row['count'] }}@if (! $loop->last) · @endif @endforeach</p>
        @endif
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>No</th><th>Tarih</th><th>Lokasyon · oda</th><th>Müşteri</th><th class="num">Kişi</th><th class="num">Tutar</th><th>Kaynak</th><th>Durum</th><th></th></tr></thead>
            <tbody>
                @forelse ($rows as $b)
                    <tr>
                        <td class="mono small">{{ $b->reference }}</td>
                        <td class="mono small">{{ $b->starts_at->format('d.m.Y H:i') }}–{{ $b->ends_at->format('H:i') }}</td>
                        <td>{{ $b->location->name }} · {{ $b->room->name }}</td>
                        <td>{{ $b->customerLabel() }}<span class="small muted" style="display:block">{{ $b->contactName() }}@if ($b->customer_phone) · {{ $b->customer_phone }}@endif</span></td>
                        <td class="num mono">{{ $b->participant_count }}</td>
                        <td class="num mono">{{ money($b->total_amount) }}</td>
                        <td class="small">{{ \App\Models\Booking::SOURCES[$b->source] ?? $b->source }}</td>
                        <td><span class="badge badge--{{ $b->status->badge() }}">{{ $b->statusLabel() }}</span>@if ($b->overridden) <span class="badge badge--warn" title="Kural dışı (JIT)">JIT</span>@endif</td>
                        <td><a href="{{ route('panel.bookings.show', [$b->location, $b->id]) }}" class="btn btn--ghost btn--pill">Aç</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="muted">Bu sekmede kayıt yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:16px">{{ $rows->links() }}</div>
@endsection
