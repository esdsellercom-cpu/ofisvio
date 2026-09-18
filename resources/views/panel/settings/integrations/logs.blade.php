@extends('layouts.panel')

@section('title', 'API logları')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.settings.api') }}">Entegrasyon merkezi</a></p>
            <h1 class="h2">API log ve izleme</h1>
            <p>Integration Gateway üzerinden geçen her dış istek: sağlayıcı, yöntem, yol (sorgu dizgisi hariç), HTTP durumu, süre, hata. Başlık/gövde, parola, token, API anahtarı ve kişisel veri loglanmaz.</p>
        </div>
    </div>
    <div class="kpis" style="margin-bottom:16px">
        <div class="kpi"><span class="k">Son 24 saat istek</span><span class="v">{{ $stats['total_24h'] }}</span><span class="d">Gateway</span></div>
        <div class="kpi {{ $stats['errors_24h'] > 0 ? 'watch' : 'ok' }}"><span class="k">Hata</span><span class="v">{{ $stats['errors_24h'] }}</span><span class="d">son 24 saat</span></div>
        <div class="kpi"><span class="k">Ort. süre</span><span class="v">{{ $stats['avg_ms_24h'] }} ms</span><span class="d">son 24 saat</span></div>
    </div>
    <form method="GET" class="inline-form" style="margin-bottom:12px;gap:8px;flex-wrap:wrap">
        <select class="control" name="saglayici" style="max-width:220px"><option value="">Tüm sağlayıcılar</option>@foreach ($providers as $p)<option value="{{ $p }}" @selected($provider === $p)>{{ $p }}</option>@endforeach</select>
        <select class="control" name="durum" style="max-width:160px"><option value="all" @selected($only === 'all')>Hepsi</option><option value="error" @selected($only === 'error')>Yalnız hata</option></select>
        <button type="submit" class="btn btn--ghost btn--pill">Süz</button>
    </form>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Tarih</th><th>Kaynak</th><th>Yöntem</th><th>Endpoint</th><th>Durum</th><th>Süre</th><th>Hata</th></tr></thead>
        <tbody>
            @forelse ($logs as $log)
                <tr><td class="small">{{ $log->created_at->format('d.m.Y H:i:s') }}</td><td class="mono small">{{ $log->provider }}</td><td class="mono small">{{ $log->method }}</td><td class="mono small">{{ $log->path }}</td><td><span class="badge badge--{{ $log->ok ? 'ok' : 'danger' }}">{{ $log->status ?? ($log->ok ? 'ok' : '—') }}</span></td><td class="mono small">{{ $log->duration_ms }} ms</td><td class="small" style="color:var(--danger)">{{ $log->error }}</td></tr>
            @empty
                <tr><td colspan="7" class="body-muted">Kayıt yok.</td></tr>
            @endforelse
        </tbody>
    </table></div>
    <div style="margin-top:12px">{{ $logs->links() }}</div>
@endsection
