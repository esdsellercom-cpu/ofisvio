@extends('layouts.panel')

@section('title', 'Yavaş sorgular')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Performance Command Center</p>
            <h1 class="h2">Yavaş sorgular — eşik ≥ {{ $threshold }} ms</h1>
            <p>Son 7 gün; aynı SQL gruplanır (bağlamsız, ? yer tutuculu — kişisel veri taşımaz). Sıralama: adet × ortalama süre. Eşik PERF_SLOW_QUERY_MS ile değişir; kayıtlar 30 gün sonra budanır.</p>
        </div>
    </div>
    @include('panel.performance.partials.nav')
    @if ($queries === [])
        <div class="panel"><span class="badge badge--ok">Eşiği aşan sorgu yok</span></div>
    @else
        <div class="stack" style="gap:10px">
            @foreach ($queries as $q)
                <div class="panel">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
                        <span class="badge badge--{{ $q['max_ms'] >= $threshold * 5 ? 'danger' : 'warn' }}">{{ $q['count'] }} kez · ort. {{ $q['avg_ms'] }} ms · maks. {{ $q['max_ms'] }} ms</span>
                        <span class="small muted">son: {{ $q['last_at'] }} · rotalar: {{ implode(', ', $q['routes']) ?: '—' }}</span>
                    </div>
                    <pre class="mono small" style="white-space:pre-wrap;word-break:break-word;margin:0;background:var(--surface-2);padding:10px;border-radius:var(--r-sm)">{{ $q['sql'] }}</pre>
                </div>
            @endforeach
        </div>
    @endif
@endsection
