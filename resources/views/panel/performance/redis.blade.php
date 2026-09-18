@extends('layouts.panel')

@section('title', 'Redis')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Performance Command Center</p>
            <h1 class="h2">Redis</h1>
            <p>Önbellek / oturum / kuyruk sürücüsü Redis ise INFO özeti; değilse yapılandırma durumu. Anahtar şeması: <span class="mono">site:{id}:s{şema}:v{sürüm}:i{kurulum}:{dil}:{ad}</span> — kiracı (website) sürümü, kurulum kimliği ve dil anahtarda; bir sitenin anahtarı başka siteye dönemez.</p>
        </div>
    </div>
    @include('panel.performance.partials.nav')
    <div class="kpis" style="margin-bottom:18px">
        <div class="kpi"><span class="k">Önbellek sürücüsü</span><span class="v" style="font-size:16px">{{ $latency['cache_store'] }}</span><span class="d">CACHE_STORE</span></div>
        <div class="kpi"><span class="k">Redis</span><span class="v" style="font-size:16px">{{ $latency['redis'] }}</span><span class="d">{{ $latency['redis_ms'] !== null ? $latency['redis_ms'].' ms ping' : 'ölçülmedi' }}</span></div>
        <div class="kpi"><span class="k">DB</span><span class="v" style="font-size:16px">{{ $latency['db_driver'] }}</span><span class="d">{{ $latency['db_ms'] ?? '—' }} ms select 1</span></div>
    </div>
    @if ($info === null)
        <div class="note">Redis yapılandırılmamış (CACHE_STORE / SESSION_DRIVER / QUEUE_CONNECTION redis değil). Uygulama önbelleği database sürücüsüyle çalışır; üretimde Redis önerilir (DEPLOY.md).</div>
    @elseif (isset($info['error']))
        <div class="note c">Redis erişilemedi: {{ $info['error'] }}</div>
    @else
        <div class="panel"><dl class="dl">
            <dt>Sürüm</dt><dd class="mono">{{ $info['version'] }}</dd>
            <dt>Bellek</dt><dd class="mono">{{ $info['used_memory_human'] }} / {{ $info['maxmemory_human'] }}</dd>
            <dt>Anahtar (db0)</dt><dd class="mono">{{ $info['keys'] }}</dd>
            <dt>İsabet oranı</dt><dd class="mono">{{ $info['hit_ratio'] !== null ? '%'.$info['hit_ratio'] : '—' }} ({{ $info['keyspace_hits'] }} / {{ $info['keyspace_misses'] }})</dd>
            <dt>Tahliye</dt><dd class="mono">{{ $info['evicted_keys'] }}</dd>
            <dt>İstemci / uptime</dt><dd class="mono">{{ $info['connected_clients'] }} · {{ $info['uptime_days'] }} gün</dd>
        </dl></div>
    @endif
@endsection
