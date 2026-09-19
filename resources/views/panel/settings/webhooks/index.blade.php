@extends('layouts.panel')

@section('title', 'Webhook merkezi')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.settings.api') }}">Entegrasyon merkezi</a></p>
            <h1 class="h2">Webhook merkezi</h1>
            <p>Sistem olaylarını dış uç noktalara imzalı JSON POST ile bildirir (<span class="mono">X-Ofisvio-Signature</span>, HMAC-SHA256). Secret şifreli saklanır ve yalnız üretildiği anda bir kez gösterilir. Başarısız teslimatlar ucun ayarına göre artan aralıkla yeniden denenir; loglar kişisel veri taşımaz.</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.settings.webhooks.deliveries') }}" class="btn btn--ghost btn--pill">Teslimat logu</a>
            <a href="{{ route('panel.settings.webhooks.create') }}" class="btn btn--brand btn--pill">Webhook oluştur</a>
        </div>
    </div>

    <div class="kpis" style="margin-bottom:16px">
        <div class="kpi"><span class="k">Uç nokta</span><span class="v">{{ $endpoints->count() }}</span><span class="d">{{ $endpoints->where('is_active', true)->count() }} aktif</span></div>
        <div class="kpi"><span class="k">Son 24 saat</span><span class="v">{{ $stats['total_24h'] }}</span><span class="d">teslimat</span></div>
        <div class="kpi {{ $stats['failed_24h'] > 0 ? 'alert' : 'ok' }}"><span class="k">Başarısız</span><span class="v">{{ $stats['failed_24h'] }}</span><span class="d">son 24 saat</span></div>
        <div class="kpi {{ $stats['pending'] > 0 ? 'watch' : '' }}"><span class="k">Bekleyen</span><span class="v">{{ $stats['pending'] }}</span><span class="d">kuyrukta / yeniden denemede</span></div>
    </div>

    <div class="panel">
        @if ($endpoints->isEmpty())
            <p class="body-muted" style="margin:0">Henüz webhook yok. "Webhook oluştur" ile URL, olaylar ve yeniden deneme ayarlarını tanımlayın.</p>
        @else
            <div class="table-wrap"><table class="data">
                <thead><tr><th>Ad</th><th>Adres</th><th>Olaylar</th><th>Durum</th><th>Son başarılı</th><th>Son hata</th><th>Başarısız</th><th></th></tr></thead>
                <tbody>
                    @foreach ($endpoints as $e)
                        <tr>
                            <td><strong>{{ $e->name }}</strong>@if ($e->description)<span class="small muted" style="display:block">{{ $e->description }}</span>@endif</td>
                            <td class="mono small">{{ $e->host() }}</td>
                            <td class="small">@foreach ($e->events ?? [] as $ev)<span class="badge badge--info" style="margin:0 4px 4px 0">{{ $events[$ev]['label'] ?? $ev }}</span>@endforeach</td>
                            <td><span class="badge badge--{{ $e->is_active ? 'ok' : 'muted' }}">{{ $e->is_active ? 'Aktif' : 'Pasif' }}</span></td>
                            <td class="small">{{ $e->last_success_at?->diffForHumans() ?? '—' }}</td>
                            <td class="small">{{ $e->last_failure_at?->diffForHumans() ?? '—' }}</td>
                            <td class="small">{{ $e->failed_count }} / {{ $e->deliveries_count }}</td>
                            <td style="white-space:nowrap">
                                <a href="{{ route('panel.settings.webhooks.edit', $e) }}" class="btn btn--ghost btn--pill">Ayarlar</a>
                                <form method="POST" action="{{ route('panel.settings.webhooks.test', $e) }}" style="display:inline">@csrf<button type="submit" class="btn btn--ghost btn--pill">Test gönder</button></form>
                                <form method="POST" action="{{ route('panel.settings.webhooks.toggle', $e) }}" style="display:inline">@csrf<button type="submit" class="btn btn--ghost btn--pill">{{ $e->is_active ? 'Pasife al' : 'Aktifleştir' }}</button></form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>

    <div class="panel" style="margin-top:14px">
        <p class="eyebrow">Olaylar</p>
        <table class="data"><thead><tr><th>Olay</th><th>Anahtar</th><th>Açıklama</th></tr></thead><tbody>
            @foreach ($events as $key => $def)<tr><td>{{ $def['label'] }}</td><td class="mono small">{{ $key }}</td><td class="small muted">{{ $def['description'] }}</td></tr>@endforeach
        </tbody></table>
        <p class="small muted" style="margin:10px 0 0">Doğrulama: <span class="mono">t</span> zaman damgası ve gövde için <span class="mono">HMAC_SHA256(t + "." + gövde, secret)</span> hesaplanır, <span class="mono">X-Ofisvio-Signature: t=…,v1=…</span> ile karşılaştırılır. <span class="mono">X-Ofisvio-Delivery</span> tekil teslimat kimliğidir (yinelenen teslimatları elemek için).</p>
    </div>
@endsection
