@extends('layouts.panel')

@section('title', 'Performans')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Performance Command Center · v1</p>
            <h1 class="h2">Performans</h1>
        </div>
        <div class="panel-head__actions">
            @can('performance.audit')
                <a href="{{ route('panel.performance.doctor') }}" class="btn btn--ghost">Doctor kontrolü</a>
            @endcan
            @if ($canAudit)
                <form method="POST" action="{{ route('panel.performance.measure') }}">@csrf
                    <button type="submit" class="btn btn--brand">Baseline ölç</button>
                </form>
            @endif
        </div>
    </div>

    @error('baseline')<div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror

    <div class="grid-auto" style="--min:180px;--gap:14px;margin-bottom:24px">
        <div class="card stat"><span class="stat__value mono" style="font-size:20px">{{ $cacheStore }}</span><span class="stat__label">Önbellek sürücüsü</span></div>
        <div class="card stat"><span class="stat__value mono" style="font-size:20px">{{ $dbDriver }}</span><span class="stat__label">Veritabanı</span></div>
        @php($hb = $heartbeat ? \Illuminate\Support\Carbon::parse($heartbeat) : null)
        @php($late = $hb === null || $hb->diffInMinutes(now()) > $heartbeatMax)
        <div class="card stat"><span class="stat__value" style="font-size:20px;color:{{ $late ? 'var(--danger)' : 'var(--ok)' }}">{{ $hb ? $hb->diffForHumans() : 'hiç' }}</span><span class="stat__label">Zamanlayıcı son çalışma</span></div>
        <div class="card stat"><span class="stat__value mono" style="font-size:20px">{{ $baseline ? \Illuminate\Support\Carbon::parse($baseline['generated_at'])->format('d.m H:i') : '—' }}</span><span class="stat__label">Son baseline</span></div>
    </div>

    <div class="panel" style="margin-bottom:20px">
        <p class="eyebrow">Sayfa ölçümleri (ofisvio:perf-baseline)</p>
        @if (! $baseline)
            <p class="body-muted" style="margin:0">Henüz baseline yok. CI her koşuda artefakt üretir; geliştirmede "Baseline ölç" ile alınır.</p>
        @else
            <p class="small muted" style="margin:0 0 12px">{{ $baseline['generated_at'] }} · PHP {{ $baseline['php'] }} · Laravel {{ $baseline['laravel'] }} · önbellek {{ $baseline['cache_store'] }} · {{ $baseline['runs'] }} koşu medyanı. Süre gürültülüdür; sorgu sayısı kapıdır (QueryBudgetTest).</p>
            <table class="data">
                <thead><tr><th>Sayfa</th><th class="num">Durum</th><th class="num">Sorgu</th><th class="num">Süre (ms)</th><th class="num">Tepe bellek (MB)</th></tr></thead>
                <tbody>
                    @foreach ($baseline['pages'] as $p)
                        <tr>
                            <td>{{ $p['label'] }}<span class="small muted mono" style="display:block">{{ $p['path'] }}</span></td>
                            <td class="num"><span class="badge badge--{{ $p['status'] === 200 ? 'ok' : 'danger' }}">{{ $p['status'] }}</span></td>
                            <td class="num mono">{{ $p['queries'] }}</td>
                            <td class="num mono">{{ $p['ms_median'] }}</td>
                            <td class="num mono">{{ $p['peak_mb'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="panel">
        <p class="eyebrow">Site önbellekleri</p>
        <table class="data">
            <thead><tr><th>Site</th><th class="num">Sürüm</th><th class="num">TTL (sn)</th><th class="num">İsabet</th><th class="num">Iskalama</th><th class="num">Oran</th></tr></thead>
            <tbody>
                @foreach ($sites as $row)
                    @php($s = $row['stats'])
                    <tr>
                        <td>{{ $row['website']->name }}</td>
                        <td class="num mono">v{{ $s['version'] }}</td>
                        <td class="num mono">{{ $row['ttl'] }}</td>
                        <td class="num mono">{{ $s['hits'] }}</td>
                        <td class="num mono">{{ $s['misses'] }}</td>
                        <td class="num mono">{{ $s['hit_ratio'] === null ? '—' : number_format($s['hit_ratio'] * 100, 1).'%' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="small muted" style="margin:12px 0 0">Ayarlar ve geçersizleme: <a href="{{ route('panel.cache.index') }}">Önbellek</a>.</p>
    </div>
@endsection
