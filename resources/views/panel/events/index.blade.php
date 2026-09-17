@extends('layouts.panel')

@section('title', 'Etkinlikler & topluluk')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Büyüme · topluluk</p>
            <h1 class="h2">Etkinlikler &amp; topluluk</h1>
            <p>Yayınlanan etkinlikler vitrinde <code class="mono">/etkinlikler</code> altında listelenir; kayıtlar KVKK rızasıyla alınır, kontenjan otomatik kapanır.</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('site.events') }}" class="btn btn--ghost" target="_blank" rel="noopener">Vitrinde gör</a>
            @can('event.manage')<a href="{{ route('panel.events.create') }}" class="btn btn--brand">Yeni etkinlik</a>@endcan
        </div>
    </div>

    @error('event')<div class="notice notice--error" role="alert" style="margin-bottom:18px"><span class="notice__dot"></span><div>{{ $message }}</div></div>@enderror

    <div class="stack" style="gap:18px">
        <div class="kpis" style="grid-template-columns:repeat(3,minmax(0,1fr))">
            <div class="kpi"><span class="k">Yaklaşan (yayında)</span><span class="v">{{ $stats['upcoming'] }}</span><span class="d">{{ $stats['next'] ? 'Sıradaki: '.$stats['next']->title.' · '.$stats['next']->starts_at->format('d.m.Y H:i') : 'Planlı etkinlik yok' }}</span></div>
            <div class="kpi"><span class="k">Kayıt (30g)</span><span class="v">{{ $stats['registrations_30d'] }}</span><span class="d">Vitrin formundan</span></div>
            <div class="kpi"><span class="k">Bu sekme</span><span class="v">{{ $events->count() }}</span><span class="d">{{ $tabs[$tab] }}</span></div>
        </div>

        <div class="card">
            <div class="card__head">
                <nav class="tabbar" style="border:0" aria-label="Etkinlik sekmeleri">
                    @foreach ($tabs as $k => $label)<a href="{{ route('panel.events.index', ['sekme' => $k]) }}" @if ($tab === $k) aria-current="page" @endif>{{ $label }}</a>@endforeach
                </nav>
            </div>
            @if ($events->isEmpty())
                <div class="empty-state" style="border:0">Bu sekmede etkinlik yok.</div>
            @else
                <div class="tw">
                    <table class="t">
                        <thead><tr><th>Etkinlik</th><th>Tarih</th><th>Yer</th><th class="num">Kayıt</th><th>Durum</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($events as $e)
                                <tr>
                                    <td><b><a href="{{ route('panel.events.show', $e) }}">{{ $e->title }}</a></b>@if ($e->summary)<br><span class="mini">{{ Str::limit($e->summary, 80) }}</span>@endif</td>
                                    <td class="mono small">{{ $e->starts_at->format('d.m.Y H:i') }}–{{ $e->ends_at->format($e->ends_at->isSameDay($e->starts_at) ? 'H:i' : 'd.m.Y H:i') }}</td>
                                    <td>{{ $e->location?->name ?? 'Çevrimiçi / belirtilmedi' }}</td>
                                    <td class="num">{{ $e->registrations_active_count }}{{ $e->capacity ? ' / '.$e->capacity : '' }}</td>
                                    <td>@if ($e->is_published)<span class="pill g">Yayında</span>@else<span class="pill n">Taslak</span>@endif @if (! $e->registration_open)<span class="pill w flat">kayıt kapalı</span>@elseif ($e->isFull())<span class="pill c flat">dolu</span>@endif</td>
                                    <td class="num">@can('event.manage')<a href="{{ route('panel.events.edit', $e) }}" class="btn btn--quiet">Düzenle</a>@endcan</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
