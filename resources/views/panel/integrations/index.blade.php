@extends('layouts.panel')

@section('title', 'Entegrasyonlar & API')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Sistem · entegrasyon geçidi</p>
            <h1 class="h2">Entegrasyonlar &amp; API</h1>
            <p>Dış sağlayıcılara tek çıkış <code class="mono">Integrations\Gateway</code>. Sağlayıcılar env ile açılır; kimlik bilgileri yalnız env'de, burada maskeli. Giden istekler ve gelen webhook'lar Denetim kaydında.</p>
        </div>
        <div class="panel-head__actions">
            @canany(['notification.view', 'notification.manage'])<a href="{{ route('panel.notifications.index', ['sekme' => 'gunluk']) }}" class="btn btn--ghost">Bildirim günlüğü</a>@endcanany
            @can('audit.view')<a href="{{ route('panel.audit.index') }}" class="btn btn--ghost">Denetim kaydı</a>@endcan
        </div>
    </div>

    @php($broken = $providers->filter(fn ($p) => $p['enabled'] && $p['missing'] !== []))

    <div class="stack" style="gap:18px">
        <div class="kpis">
            <div class="kpi"><span class="k">Sağlayıcı</span><span class="v">{{ $providers->count() }}</span><span class="d">{{ $providers->where('enabled', true)->count() }} açık</span></div>
            <div class="kpi {{ $broken->isNotEmpty() ? 'alert' : 'ok' }}"><span class="k">Eksik kimlik</span><span class="v">{{ $broken->count() }}</span><span class="d">Açık olup secret'ı eksik sağlayıcı</span></div>
            <div class="kpi"><span class="k">Webhook ucu</span><span class="v">{{ $providers->where('webhook', true)->count() }}</span><span class="d">İmza anahtarı tanımlı</span></div>
            <div class="kpi"><span class="k">Zaman aşımı</span><span class="v">{{ $timeout }} sn</span><span class="d">Geçit isteği</span></div>
        </div>

        <div class="card">
            <div class="card__head"><h3>Sağlayıcılar</h3><span class="sub">config/integrations.php · env</span></div>
            <div class="tw">
                <table class="t">
                    <thead><tr><th>Sağlayıcı</th><th>Durum</th><th>Adres</th><th>Kimlik</th><th>Webhook</th></tr></thead>
                    <tbody>
                        @foreach ($providers as $p)
                            <tr>
                                <td><b>{{ $p['label'] }}</b><br><span class="mini mono">{{ $p['key'] }}</span></td>
                                <td>
                                    @if ($p['enabled'] && $p['missing'] === [])
                                        <span class="pill g">Açık</span>
                                    @elseif ($p['enabled'])
                                        <span class="pill c">Açık · kimlik eksik</span>
                                    @else
                                        <span class="pill n">Kapalı</span>
                                    @endif
                                </td>
                                <td class="mono small">{{ $p['base_url'] ?: '—' }}</td>
                                <td class="small">@foreach ($p['secrets'] as $k => $v)<span class="mono">{{ $k }}</span>: {{ $v }}@if (! $loop->last)<br>@endif @endforeach</td>
                                <td class="small">{{ $p['webhook'] ? 'POST /webhooks/'.$p['key'] : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid g-2-1">
            <div class="card">
                <div class="card__head"><h3>Bildirim kanalı sağlığı</h3><span class="sub">Son 7 gün</span></div>
                <div class="tw">
                    <table class="t">
                        <thead><tr><th>Kanal</th><th class="num">Gönderildi</th><th class="num">Başarısız</th><th class="num">Kuyrukta</th><th>Son gönderim</th></tr></thead>
                        <tbody>
                            @foreach ($health as $channel => $h)
                                <tr>
                                    <td><b>{{ ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'E-posta', 'in_app' => 'Uygulama içi'][$channel] ?? $channel }}</b></td>
                                    <td class="num">{{ $h['sent'] }}</td>
                                    <td class="num">@if ($h['failed'] > 0)<span class="pill c">{{ $h['failed'] }}</span>@else 0 @endif</td>
                                    <td class="num">{{ $h['queued'] }}</td>
                                    <td class="mono small">{{ $h['last_sent_at'] ? \Illuminate\Support\Carbon::parse($h['last_sent_at'])->format('d.m.Y H:i') : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <div class="card__head"><h3>API</h3></div>
                <div class="card__body">
                    <p class="small" style="margin:0">Dışa açık (public) API henüz yok; panel ve vitrin sunucu tarafında çalışır. Dış sistemlerle bağlantı yalnız sağlayıcı geçidi ve imzalı webhook uçları üzerinden kurulur.</p>
                    <dl class="kv" style="margin-top:8px">
                        <dt>Gelen</dt><dd>POST /webhooks/{sağlayıcı} — imza + zaman damgası doğrulaması</dd>
                        <dt>Giden</dt><dd>Gateway → https zorunlu, SSRF koruması (UrlGuard), denetim kaydı</dd>
                        <dt>Kimlik</dt><dd>Yalnız env; veritabanında ve kodda tutulmaz, loglanmaz</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
