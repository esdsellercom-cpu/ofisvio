@extends('layouts.panel')

@section('title', 'Sorgu performansı')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Performance Command Center</p>
            <h1 class="h2">Sorgu performansı — rota bazında</h1>
            <p>Son 7 günün istek örneklerinden: ortalama sorgu sayısı, DB süresi, p95 yanıt süresi, yanıt boyutu. Sorgu sayısı kapıdır (QueryBudgetTest, CI'da deterministik); süre gürültülüdür. Kayıt sayısıyla büyüyen sorgu sayısı N+1 sinyalidir.</p>
        </div>
    </div>
    @include('panel.performance.partials.nav')
    @if ($routes === [])
        <div class="panel"><p class="body-muted" style="margin:0">Örnek yok (örnekleme %{{ ($config['sample_rate'] ?? 0) * 100 }}).</p></div>
    @else
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Rota</th><th>Tür</th><th>Örnek</th><th>Sorgu (ort.)</th><th>DB ms (ort.)</th><th>p95 ms</th><th>Yanıt</th></tr></thead>
            <tbody>
                @foreach ($routes as $r)
                    <tr class="{{ $r['queries_avg'] > 30 ? 'is-warn' : '' }}"><td class="mono small">{{ $r['route'] }}</td><td class="small">{{ $r['kind'] }}</td><td class="mono">{{ $r['count'] }}</td><td class="mono">{{ $r['queries_avg'] }}@if ($r['queries_avg'] > 30) <span class="badge badge--warn">yüksek</span>@endif</td><td class="mono">{{ $r['query_ms_avg'] }}</td><td class="mono">{{ $r['p95'] ?? '—' }}</td><td class="mono">{{ number_format($r['bytes_avg'] / 1024, 1, ',', '.') }} KB</td></tr>
                @endforeach
            </tbody>
        </table></div>
    @endif
@endsection
