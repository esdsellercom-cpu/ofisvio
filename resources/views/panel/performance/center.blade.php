@extends('layouts.panel')

@section('title', 'Performance Dashboard')

@section('content')
    @php($o = $overview)
    @php($site = $o['by_kind']['site'])
    @php($panel = $o['by_kind']['panel'])
    <div class="panel-head">
        <div>
            <p class="eyebrow">Performance Command Center</p>
            <h1 class="h2">Performans dashboard</h1>
            <p>Gerçek ölçümler: istek profili (örnekleme %{{ $o['config']['sample_rate'] * 100 }}, TTFB yaklaşığı = uygulama yanıt süresi), sorgu sayısı/süresi, yanıt boyutu, önbellek isabeti, DB/Redis/kuyruk gecikmesi (şimdi ölçüldü), Core Web Vitals (PageSpeed). Mimari: tarayıcı → CDN/edge (dış) → HTTP önbelleği (ETag/304, public max-age) → uygulama → sürümlü uygulama önbelleği (Redis/DB) → MySQL.</p>
        </div>
        <div class="panel-head__actions">
            @foreach ([1 => 'Son 1 saat', 24 => 'Son 24 saat', 168 => 'Son 7 gün'] as $h => $label)<a href="{{ route('panel.performance.center', ['saat' => $h]) }}" class="btn btn--ghost btn--pill" @if ($hours === $h) aria-current="page" @endif>{{ $label }}</a>@endforeach
        </div>
    </div>

    @include('panel.performance.partials.nav')

    @if ($o['samples'] === 0)
        <div class="note w" style="margin-bottom:18px">Bu aralıkta istek örneği yok (örnekleme %{{ $o['config']['sample_rate'] * 100 }}; PERF_SAMPLE_RATE ile artırılır). Rakamlar örnek biriktikçe dolar; tahmini değer basılmaz.</div>
    @endif

    <div class="kpis kpis--6" style="margin-bottom:18px">
        <div class="kpi {{ ($site['p95'] ?? 0) > 800 ? 'alert' : (($site['p95'] ?? 0) > 400 ? 'watch' : 'ok') }}"><span class="k">Vitrin TTFB p50 / p95</span><span class="v">{{ $site['p50'] ?? '—' }} / {{ $site['p95'] ?? '—' }} ms</span><span class="d">{{ $site['count'] }} örnek</span></div>
        <div class="kpi"><span class="k">Panel TTFB p50 / p95</span><span class="v">{{ $panel['p50'] ?? '—' }} / {{ $panel['p95'] ?? '—' }} ms</span><span class="d">{{ $panel['count'] }} örnek</span></div>
        <div class="kpi {{ ($site['queries_avg'] ?? 0) > 30 ? 'watch' : 'ok' }}"><span class="k">Sorgu / istek (vitrin)</span><span class="v">{{ $site['queries_avg'] ?? '—' }}</span><span class="d">{{ $site['query_ms_avg'] ?? '—' }} ms DB süresi</span></div>
        <div class="kpi"><span class="k">Yanıt boyutu (vitrin)</span><span class="v">{{ $site['bytes_avg'] !== null ? number_format($site['bytes_avg'] / 1024, 1, ',', '.').' KB' : '—' }}</span><span class="d">ort. HTML</span></div>
        <div class="kpi"><span class="k">HTTP önbellek isabeti</span><span class="v">{{ $site['cache_hit_ratio'] !== null ? '%'.$site['cache_hit_ratio'] : '—' }}</span><span class="d">304 yanıtları (misafir)</span></div>
        <div class="kpi {{ $o['slow_queries'] > 0 ? 'watch' : 'ok' }}"><span class="k">Yavaş sorgu</span><span class="v">{{ $o['slow_queries'] }}</span><span class="d">≥ {{ $o['config']['slow_query_ms'] }} ms</span></div>
    </div>

    <div class="kpis kpis--6" style="margin-bottom:18px">
        <div class="kpi {{ ($o['latency']['db_ms'] ?? 99) > 20 ? 'watch' : 'ok' }}"><span class="k">DB gecikmesi</span><span class="v">{{ $o['latency']['db_ms'] ?? '—' }} ms</span><span class="d">{{ $o['latency']['db_driver'] }} · select 1</span></div>
        <div class="kpi"><span class="k">Redis gecikmesi</span><span class="v">{{ $o['latency']['redis_ms'] ?? '—' }}{{ $o['latency']['redis_ms'] !== null ? ' ms' : '' }}</span><span class="d">{{ $o['latency']['redis'] }}</span></div>
        <div class="kpi"><span class="k">Uygulama önbelleği</span><span class="v">{{ $cacheRoundTrip ?? '—' }} ms</span><span class="d">{{ $o['latency']['cache_store'] }} · yaz/oku/sil</span></div>
        <div class="kpi"><span class="k">Önbellek isabet oranı</span><span class="v">{{ $cacheStats && $cacheStats['hit_ratio'] !== null ? '%'.round($cacheStats['hit_ratio'] * 100, 1) : '—' }}</span><span class="d">{{ $cacheStats ? $cacheStats['hits'].' isabet · '.$cacheStats['misses'].' ıskalama · v'.$cacheStats['version'] : 'site yok' }}</span></div>
        <div class="kpi {{ ($o['queue']['oldest_seconds'] ?? 0) > 300 || ($o['queue']['failed'] ?? 0) > 0 ? 'watch' : 'ok' }}"><span class="k">Kuyruk</span><span class="v">{{ $o['queue']['pending'] ?? '—' }}</span><span class="d">{{ $o['queue']['driver'] }} · en eski {{ $o['queue']['oldest_seconds'] ?? '—' }} sn · {{ $o['queue']['failed'] ?? '—' }} başarısız</span></div>
        <div class="kpi"><span class="k">Bellek (vitrin)</span><span class="v">{{ $site['memory_avg'] ?? '—' }} MB</span><span class="d">tepe, ortalama</span></div>
    </div>

    <div class="grid-auto" style="--min:320px;--gap:18px;align-items:start">
        <div class="panel">
            <p class="eyebrow">Core Web Vitals (son ölçüm)</p>
            @if ($vitals->isEmpty())<p class="body-muted small" style="margin:0">Ölçüm yok — <a href="{{ route('panel.performance.vitals') }}">Core Web Vitals</a> sayfasından PageSpeed ile ölçün (PAGESPEED_ENABLED).</p>@else
                <table class="data"><thead><tr><th>Sayfa</th><th>Cihaz</th><th>Skor</th><th>LCP</th><th>INP</th><th>CLS</th></tr></thead><tbody>
                    @foreach ($vitals->take(8) as $row)
                        @php($s = $row['sample'])
                        <tr><td class="mono small">{{ $s->path }}</td><td class="small">{{ $s->strategy }}</td><td class="mono">{{ $s->score ?? '—' }}</td>
                            @foreach (['lcp_ms', 'inp_ms', 'cls'] as $m)<td><span class="badge badge--{{ ['good' => 'ok', 'needs' => 'warn', 'poor' => 'danger', 'none' => 'muted'][$row['grades'][$m]] }}">{{ $s->field[$m] ?? $s->lab[$m] ?? '—' }}</span></td>@endforeach
                        </tr>
                    @endforeach
                </tbody></table>
            @endif
        </div>
        <div class="panel">
            <p class="eyebrow">Son önbellek olayları (geçersizleme kaskadı)</p>
            @if ($events->isEmpty())<p class="body-muted small" style="margin:0">Olay yok.</p>@else
                <ul class="small" style="margin:0;padding-left:18px">@foreach ($events as $e)<li><span class="mono muted">{{ $e->created_at->format('d.m H:i') }}</span> {{ $e->trigger }} #{{ $e->entity_id }} → v{{ $e->version_after }} <span class="muted">({{ count($e->steps) }} adım)</span></li>@endforeach</ul>
                <p class="small muted" style="margin:8px 0 0">Ayrıntı: <a href="{{ route('panel.performance.http-cache') }}">Cache politikaları</a>.</p>
            @endif
        </div>
    </div>
@endsection
