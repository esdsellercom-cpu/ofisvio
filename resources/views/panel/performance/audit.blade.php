@extends('layouts.panel')

@section('title', 'Performans denetimi')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Performance Command Center</p>
            <h1 class="h2">Performans denetimi</h1>
            <p>Uygulama katmanı kontrol listesi gerçek durumdan (config/route/view önbelleği, OPcache, debug, sürücüler, HTTP önbelleği, istek profili). Üretimde kırmızı maddeler doctor'da da hatadır.</p>
        </div>
        <div class="panel-head__actions"><a href="{{ route('panel.settings.health') }}" class="btn btn--ghost btn--pill">Sistem sağlığı (doctor)</a></div>
    </div>
    @include('panel.performance.partials.nav')
    <div class="panel" style="margin-bottom:18px">
        <table class="data"><thead><tr><th>Madde</th><th>Durum</th><th>Not</th></tr></thead><tbody>
            @foreach ($rows as $r)<tr><td>{{ $r['name'] }}</td><td><span class="badge badge--{{ ['ok' => 'ok', 'warn' => 'warn', 'fail' => 'danger'][$r['level']] }}">{{ ['ok' => 'tamam', 'warn' => 'uyarı', 'fail' => 'hata'][$r['level']] }}</span></td><td class="small">{{ $r['note'] }}</td></tr>@endforeach
        </tbody></table>
    </div>
    <div class="kpis" style="margin-bottom:18px">
        <div class="kpi"><span class="k">DB gecikmesi</span><span class="v">{{ $latency['db_ms'] ?? '—' }} ms</span><span class="d">{{ $latency['db_driver'] }}</span></div>
        <div class="kpi"><span class="k">Redis</span><span class="v" style="font-size:16px">{{ $latency['redis_ms'] !== null ? $latency['redis_ms'].' ms' : $latency['redis'] }}</span><span class="d">{{ $latency['cache_store'] }}</span></div>
        <div class="kpi"><span class="k">Kuyruk</span><span class="v">{{ $queue['pending'] ?? '—' }}</span><span class="d">{{ $queue['driver'] }} · en eski {{ $queue['oldest_seconds'] ?? '—' }} sn</span></div>
        <div class="kpi {{ ($queue['failed'] ?? 0) > 0 ? 'alert' : 'ok' }}"><span class="k">Başarısız iş</span><span class="v">{{ $queue['failed'] ?? '—' }}</span><span class="d">failed_jobs</span></div>
    </div>
@endsection
