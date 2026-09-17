@extends('layouts.panel')

@section('title', $event->title)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.events.index') }}">Etkinlikler</a> / {{ $event->title }}</p>
            <h1 class="h2">{{ $event->title }}</h1>
            <p>@if ($event->is_published)<span class="pill g">Yayında</span>@else<span class="pill n">Taslak</span>@endif {{ $event->starts_at->format('d.m.Y H:i') }} – {{ $event->ends_at->format('d.m.Y H:i') }} · {{ $event->location?->name ?? 'Çevrimiçi / belirtilmedi' }}@if ($event->room) · {{ $event->room->name }}@endif · {{ $event->price > 0 ? money($event->price) : 'Ücretsiz' }}</p>
        </div>
        <div class="panel-head__actions">
            @if ($event->is_published)<a href="{{ $event->path() }}" class="btn btn--ghost" target="_blank" rel="noopener">Vitrinde gör</a>@endif
            @can('event.manage')
                <a href="{{ route('panel.events.edit', $event) }}" class="btn btn--ghost">Düzenle</a>
                @if ($event->registrations->isEmpty())
                    <form method="POST" action="{{ route('panel.events.destroy', $event) }}" onsubmit="return confirm('Etkinlik silinsin mi?')">@csrf @method('DELETE')<button type="submit" class="btn btn--danger">Sil</button></form>
                @endif
            @endcan
        </div>
    </div>

    @error('event')<div class="notice notice--error" role="alert" style="margin-bottom:18px"><span class="notice__dot"></span><div>{{ $message }}</div></div>@enderror

    <div class="grid g-2-1">
        <div class="card">
            <div class="card__head"><h3>Katılımcılar</h3><span class="sub">{{ $event->registrations->where('status', '!=', 'cancelled')->count() }} aktif kayıt{{ $event->capacity ? ' / '.$event->capacity.' kontenjan' : '' }}</span></div>
            @if ($event->registrations->isEmpty())
                <div class="empty-state" style="border:0">Henüz kayıt yok.</div>
            @else
                <div class="tw">
                    <table class="t">
                        <thead><tr><th>Kişi</th><th>İletişim</th><th>Not</th><th>Durum</th>@can('event.manage')<th></th>@endcan</tr></thead>
                        <tbody>
                            @foreach ($event->registrations as $r)
                                <tr>
                                    <td><b>{{ $r->name }}</b>@if ($r->company_name)<br><span class="mini">{{ $r->company_name }}</span>@endif</td>
                                    <td class="small">{{ $r->email }}@if ($r->phone)<br>{{ $r->phone }}@endif</td>
                                    <td class="small">{{ $r->note ?? '—' }}</td>
                                    <td><span class="pill {{ ['registered' => 'i', 'attended' => 'g', 'cancelled' => 'n'][$r->status] }}">{{ $r->statusLabel() }}</span><br><span class="mini">{{ $r->created_at->format('d.m.Y H:i') }}</span></td>
                                    @can('event.manage')
                                        <td class="num">
                                            <form method="POST" action="{{ route('panel.events.registration', [$event, $r]) }}" class="inline-form" style="justify-content:flex-end;gap:4px">
                                                @csrf
                                                <select class="control" name="status" style="width:auto;padding:4px 26px 4px 8px">@foreach ($statuses as $k => $label)<option value="{{ $k }}" @selected($r->status === $k)>{{ $label }}</option>@endforeach</select>
                                                <button type="submit" class="btn btn--ghost btn--pill">Güncelle</button>
                                            </form>
                                        </td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        <div class="card">
            <div class="card__head"><h3>Etkinlik</h3></div>
            <div class="card__body">
                <dl class="kv">
                    <dt>Bağlantı</dt><dd class="mono">{{ $event->path() }}</dd>
                    <dt>Kayıt</dt><dd>{{ $event->registration_open ? 'Açık' : 'Kapalı' }}{{ $event->isFull() ? ' · kontenjan doldu' : '' }}</dd>
                    <dt>Kontenjan</dt><dd>{{ $event->capacity ?? 'Sınırsız' }}</dd>
                    @if ($event->summary)<dt>Özet</dt><dd>{{ $event->summary }}</dd>@endif
                </dl>
                @if ($event->description)<div class="prose" style="margin-top:12px;font-size:13.5px">{!! $event->renderedDescription() !!}</div>@endif
            </div>
        </div>
    </div>
@endsection
