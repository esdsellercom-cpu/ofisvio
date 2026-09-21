@extends('layouts.panel')

@section('title', 'Rezervasyon takvimi — '.$location->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">@can('booking.view')<a href="{{ route('panel.bookings.index') }}">Rezervasyonlar</a> · @endcan<a href="{{ route('panel.bookings.location', $location) }}">{{ $location->name }} masası</a></p>
            <h1 class="h2">Takvim · {{ $view === 'week' ? $from->format('d.m').' – '.$from->copy()->addDays(6)->format('d.m.Y') : $from->format('d.m.Y') }}</h1>
        </div>
        <div class="panel-head__actions">
            <form method="GET" class="inline-form">
                <select class="control" name="gorunum" data-autosubmit><option value="week" @selected($view === 'week')>Hafta</option><option value="day" @selected($view === 'day')>Gün</option></select>
                <input class="control" type="date" name="gun" value="{{ $from->toDateString() }}" data-autosubmit>
            </form>
            <a href="{{ route('panel.bookings.calendar', [$location, 'gorunum' => $view, 'gun' => $from->copy()->subDays($view === 'week' ? 7 : 1)->toDateString()]) }}" class="btn btn--ghost">‹</a>
            <a href="{{ route('panel.bookings.calendar', [$location, 'gorunum' => $view, 'gun' => $today->toDateString()]) }}" class="btn btn--ghost">Bugün</a>
            <a href="{{ route('panel.bookings.calendar', [$location, 'gorunum' => $view, 'gun' => $from->copy()->addDays($view === 'week' ? 7 : 1)->toDateString()]) }}" class="btn btn--ghost">›</a>
        </div>
    </div>

    <p class="small muted" style="margin:0 0 14px">Lokasyon: @foreach ($locations as $loc)@can('booking.view', $loc)<a href="{{ route('panel.bookings.calendar', [$loc, 'gorunum' => $view, 'gun' => $from->toDateString()]) }}" @if ($loc->id === $location->id) style="font-weight:600" @endif>{{ $loc->name }}</a>@if (! $loop->last) · @endif @endcan @endforeach</p>

    <div class="table-wrap">
        <table class="data" style="table-layout:fixed;min-width:{{ $view === 'week' ? 980 : 480 }}px">
            <thead><tr><th style="width:160px">Oda</th>@foreach ($days as $d)<th @if ($d->isSameDay($today)) style="color:var(--brand)" @endif>{{ ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'][(int) $d->format('w')] }} {{ $d->format('d.m') }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse ($rooms as $room)
                    <tr>
                        <td><strong>{{ $room->name }}</strong><span class="small muted" style="display:block">{{ $room->open_from }}–{{ $room->open_until }}@unless ($room->is_active) · pasif @endunless</span></td>
                        @foreach ($days as $d)
                            <td style="vertical-align:top">
                                @foreach ($cells[$room->id.'|'.$d->toDateString()] ?? [] as $b)
                                    <a href="{{ route('panel.bookings.show', [$location, $b->id]) }}" class="small" style="display:block;margin:0 0 4px;padding:4px 6px;border-radius:6px;background:{{ $b->isPending() ? 'var(--warn-wash, #FFF4DC)' : 'var(--ok-wash, #E4F3E7)' }};text-decoration:none">
                                        <span class="mono">{{ $b->starts_at->format('H:i') }}–{{ $b->ends_at->format('H:i') }}</span> {{ \Illuminate\Support\Str::limit($b->customerLabel(), 18) }}
                                        <span class="badge badge--{{ $b->status->badge() }}" style="margin-left:4px">{{ $b->statusLabel() }}</span>
                                    </a>
                                @endforeach
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ $days->count() + 1 }}" class="muted">Bu lokasyonda oda yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection