@extends('layouts.panel')

@section('title', 'Entegrasyon sağlığı')

@php($tone = ['connected' => 'ok', 'unconfigured' => 'warn', 'error' => 'danger', 'disabled' => 'muted', 'link' => 'info', 'planned' => 'muted'])

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.settings.api') }}">Entegrasyon merkezi</a></p>
            <h1 class="h2">Entegrasyon sağlığı</h1>
            <p>API, webhook, e-posta, ödeme, AI, harita ve depolama durumları tek ekranda; son başarılı bağlantı ve son hata zamanı gerçek isteklerden (Gateway logu, senkron durumu). Uygulama sağlığı (doctor) ayrı ekranda.</p>
        </div>
        <div class="panel-head__actions"><a href="{{ route('panel.settings.health') }}" class="btn btn--ghost btn--pill">Sistem sağlığı (doctor)</a><a href="{{ route('panel.settings.integrations.logs') }}" class="btn btn--ghost btn--pill">API logları</a></div>
    </div>
    <div class="grid-auto" style="--min:300px;--gap:14px">
        @foreach ($rows as $group => $items)
            <div class="panel">
                <p class="eyebrow">{{ $group }}</p>
                <table class="data"><tbody>
                    @foreach ($items as $it)
                        <tr><td><a href="{{ route('panel.settings.integrations.show', $it['key']) }}">{{ $it['label'] }}</a></td><td><span class="badge badge--{{ $tone[$it['state']] }}">{{ $it['state_label'] }}</span></td>
                            <td class="small muted">son başarılı {{ $it['last_ok_at'] ? \Illuminate\Support\Carbon::parse($it['last_ok_at'])->diffForHumans() : '—' }}<br>son hata {{ $it['last_error_at'] ? \Illuminate\Support\Carbon::parse($it['last_error_at'])->diffForHumans() : '—' }}</td></tr>
                    @endforeach
                </tbody></table>
            </div>
        @endforeach
        <div class="panel">
            <p class="eyebrow">Gelen webhook olayları (son 10)</p>
            @if ($incoming->isEmpty())<p class="body-muted small" style="margin:0">Henüz gelen webhook yok.</p>@else
                <table class="data"><thead><tr><th>Sağlayıcı</th><th>Olay</th><th>Durum</th><th>Zaman</th></tr></thead><tbody>
                    @foreach ($incoming as $e)<tr><td class="mono small">{{ $e->provider }}</td><td class="mono small">{{ $e->event_id }}</td><td><span class="badge badge--{{ $e->status === 'failed' ? 'danger' : ($e->status === 'processed' ? 'ok' : 'info') }}">{{ $e->status }}</span></td><td class="small">{{ $e->received_at?->format('d.m H:i') }}</td></tr>@endforeach
                </tbody></table>
            @endif
        </div>
    </div>
@endsection
