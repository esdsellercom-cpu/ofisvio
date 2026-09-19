@extends('layouts.panel')

@section('title', 'Webhook teslimatları')

@php($statusTone = ['success' => 'ok', 'failed' => 'danger', 'pending' => 'warn'])

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.settings.webhooks.index') }}">Webhook merkezi</a></p>
            <h1 class="h2">Webhook teslimat logu</h1>
            <p>Her gönderim: olay, uç, durum, HTTP yanıtı, süre, deneme sayısı, sonraki deneme. Saklanan gövde kopyası kişisel veri taşımaz (maskeli); başarısız teslimat aynı gövdeyle tekrar gönderilebilir.</p>
        </div>
    </div>
    <div class="kpis" style="margin-bottom:16px">
        <div class="kpi"><span class="k">Son 24 saat</span><span class="v">{{ $stats['total_24h'] }}</span><span class="d">teslimat</span></div>
        <div class="kpi {{ $stats['failed_24h'] > 0 ? 'alert' : 'ok' }}"><span class="k">Başarısız</span><span class="v">{{ $stats['failed_24h'] }}</span><span class="d">son 24 saat</span></div>
        <div class="kpi"><span class="k">Bekleyen</span><span class="v">{{ $stats['pending'] }}</span><span class="d">kuyruk / yeniden deneme</span></div>
        <div class="kpi"><span class="k">Ort. yanıt</span><span class="v">{{ $stats['avg_ms_24h'] }} ms</span><span class="d">son 24 saat</span></div>
    </div>
    <form method="GET" class="inline-form" style="margin-bottom:12px;gap:8px;flex-wrap:wrap">
        <select class="control" name="uc" style="max-width:220px"><option value="">Tüm uçlar</option>@foreach ($endpoints as $e)<option value="{{ $e->id }}" @selected($endpointId === $e->id)>{{ $e->name }}</option>@endforeach</select>
        <select class="control" name="durum" style="max-width:160px"><option value="">Tüm durumlar</option>@foreach (\App\Models\WebhookDelivery::STATUSES as $k => $label)<option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>@endforeach</select>
        <select class="control" name="olay" style="max-width:220px"><option value="">Tüm olaylar</option><option value="ping" @selected($event === 'ping')>Test (ping)</option>@foreach (\App\Webhooks\WebhookEvents::REGISTRY as $k => $def)<option value="{{ $k }}" @selected($event === $k)>{{ $def['label'] }}</option>@endforeach</select>
        <button type="submit" class="btn btn--ghost btn--pill">Süz</button>
    </form>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Tarih</th><th>Olay</th><th>Uç</th><th>Durum</th><th>HTTP</th><th>Süre</th><th>Deneme</th><th>Hata / sonraki deneme</th><th></th></tr></thead>
        <tbody>
            @forelse ($deliveries as $d)
                <tr>
                    <td class="small">{{ $d->created_at->format('d.m.Y H:i:s') }}</td>
                    <td class="mono small">{{ $d->event }}@if ($d->manual)<span class="badge badge--muted" style="margin-left:4px">elle</span>@endif</td>
                    <td class="small">{{ $d->endpoint?->name ?? '—' }}<span class="mono muted" style="display:block">{{ $d->endpoint?->host() }}</span></td>
                    <td><span class="badge badge--{{ $statusTone[$d->status] }}">{{ \App\Models\WebhookDelivery::STATUSES[$d->status] }}</span></td>
                    <td class="mono small">{{ $d->response_status ?? '—' }}</td>
                    <td class="small">{{ $d->duration_ms !== null ? $d->duration_ms.' ms' : '—' }}</td>
                    <td class="small">{{ $d->attempts }} / {{ ($d->endpoint?->retry_max ?? 0) + 1 }}</td>
                    <td class="small" style="max-width:260px">@if ($d->error)<span style="color:var(--danger)">{{ $d->error }}</span>@endif @if ($d->next_retry_at)<span class="muted" style="display:block">sonraki: {{ $d->next_retry_at->format('d.m H:i') }}</span>@endif</td>
                    <td>@if ($d->status !== 'success')<form method="POST" action="{{ route('panel.settings.webhooks.resend', $d) }}">@csrf<button type="submit" class="btn btn--ghost btn--pill">Tekrar gönder</button></form>@endif</td>
                </tr>
            @empty
                <tr><td colspan="9" class="body-muted">Kayıt yok.</td></tr>
            @endforelse
        </tbody>
    </table></div>
    <div style="margin-top:12px">{{ $deliveries->links() }}</div>
@endsection
