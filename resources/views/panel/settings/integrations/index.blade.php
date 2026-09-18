@extends('layouts.panel')

@section('title', 'Entegrasyon merkezi')

@php($tone = fn (string $state) => ['connected' => 'ok', 'unconfigured' => 'warn', 'error' => 'danger', 'disabled' => 'muted', 'link' => 'info'][$state])

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.settings.index') }}">Ayarlar</a></p>
            <h1 class="h2">Entegrasyon merkezi</h1>
            <p>Bağlan → bilgileri gir → test et → aktifleştir. Her entegrasyon kendi alanlarını taşır; secret'lar şifreli saklanır, panelde yalnız <span class="mono">••••</span> görünür, loglara ve tarayıcıya gitmez. Env'de tanımlı değer varsa gösterilir; panelde girilen değer önceliklidir. Dış çağrılar yalnız Integration Gateway üzerinden (SSRF korumalı, zaman aşımlı, loglu).</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.settings.integrations.health') }}" class="btn btn--ghost btn--pill">Sistem sağlığı</a>
            <a href="{{ route('panel.settings.integrations.logs') }}" class="btn btn--ghost btn--pill">API logları</a>
            <a href="{{ route('panel.settings.api.core') }}" class="btn btn--ghost btn--pill">Çekirdek (DB, önbellek, kuyruk, tarayıcı)</a>
        </div>
    </div>

    @foreach ($categories as $category)
        <div class="panel" style="margin-bottom:14px">
            <p class="eyebrow" style="margin:0 0 2px">{{ $category['label'] }}</p>
            <p class="small muted" style="margin:0 0 10px">{{ $category['lead'] }}</p>
            <table class="data">
                <thead><tr><th>Servis</th><th>Durum</th><th>Aktif</th><th>Son bağlantı</th><th>Son senkron</th><th>Hata</th><th></th></tr></thead>
                <tbody>
                    @foreach ($category['items'] as $item)
                        <tr>
                            <td><strong>{{ $item['label'] }}</strong><span class="small muted" style="display:block">{{ $item['description'] }}</span></td>
                            <td><span class="badge badge--{{ $tone($item['state']) }}">{{ $item['state_label'] }}</span>@if ($item['missing'] !== [])<span class="small muted" style="display:block">eksik: {{ implode(', ', $item['missing']) }}</span>@endif</td>
                            <td class="small">{{ $item['kind'] === 'link' ? '—' : ($item['enabled'] ? 'Aktif' : 'Pasif') }}<span class="muted" style="display:block">{{ $item['source'] }}</span></td>
                            <td class="small">{{ $item['last_ok_at'] ? \Illuminate\Support\Carbon::parse($item['last_ok_at'])->diffForHumans() : '—' }}</td>
                            <td class="small">{{ $item['last_sync_at'] ? \Illuminate\Support\Carbon::parse($item['last_sync_at'])->diffForHumans() : '—' }}</td>
                            <td class="small" style="max-width:260px;color:var(--danger)">{{ $item['last_error'] ?? '' }}</td>
                            <td><a href="{{ route('panel.settings.integrations.show', $item['key']) }}" class="btn btn--ghost btn--pill">{{ $item['kind'] === 'link' ? 'Aç' : 'Ayarlar' }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
@endsection
