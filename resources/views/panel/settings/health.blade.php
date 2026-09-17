@extends('layouts.panel')

@section('title', 'Sistem sağlığı')

{{-- Sistem sağlığı (faz 52): ofisvio:doctor kontrolleri (tek kaynak) — veritabanı, önbellek, tarayıcı, depolama, e-posta,
     kuyruk, zamanlayıcı, seed/yönetici, entegrasyonlar. Yeniden çalıştır + son bağlantı testleri. --}}
@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Ayar merkezi · ortam: <b>{{ $environment }}</b>@if ($debug) · <span style="color:var(--danger)">APP_DEBUG açık</span>@endif</p>
            <h1 class="h2">Sistem sağlığı</h1>
            <p>{{ $ok ? 'Tüm kontroller geçti.' : 'Bazı kontroller uyarı/hata veriyor; üretimde hata çıkış kodu 1 döner (deploy kapısı).' }} Komut satırı eşdeğeri: <code>php artisan ofisvio:doctor</code>.</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.settings.api') }}" class="btn btn--ghost">API &amp; Entegrasyonlar</a>
            @can('settings.manage')<form method="POST" action="{{ route('panel.settings.health.recheck') }}">@csrf<button type="submit" class="btn btn--brand">Yeniden kontrol et</button></form>@endcan
        </div>
    </div>

    @php($pill = fn (string $l) => ['ok' => 'g', 'warn' => 'w', 'fail' => 'c'][$l] ?? 'n')
    @php($label = fn (string $l) => ['ok' => 'Çalışıyor', 'warn' => 'Uyarı', 'fail' => 'Hata'][$l] ?? $l)
    <div class="kpis" style="margin-bottom:16px">
        <div class="kpi {{ $ok ? 'ok' : 'warn' }}"><span class="k">Genel</span><span class="v">{{ $ok ? 'OK' : 'Dikkat' }}</span><span class="d">{{ count($rows) }} kontrol</span></div>
        <div class="kpi"><span class="k">Hata</span><span class="v">{{ count(array_filter($rows, fn ($r) => $r['level'] === 'fail')) }}</span><span class="d">üretimde deploy durur</span></div>
        <div class="kpi"><span class="k">Uyarı</span><span class="v">{{ count(array_filter($rows, fn ($r) => $r['level'] === 'warn')) }}</span><span class="d">geliştirmede kabul edilir</span></div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card__head"><h3>Kontroller</h3></div>
        <div class="tw"><table class="t"><thead><tr><th>Kontrol</th><th>Durum</th><th>Not</th></tr></thead><tbody>
            @foreach ($rows as $r)<tr><td><b>{{ $r['name'] }}</b></td><td><span class="pill {{ $pill($r['level']) }} flat">{{ $label($r['level']) }}</span></td><td class="small">{{ $r['note'] }}</td></tr>@endforeach
        </tbody></table></div>
    </div>

    <div class="card">
        <div class="card__head"><h3>Son bağlantı testleri</h3><span class="sub">API &amp; Entegrasyonlar › Bağlantıyı test et</span></div>
        @if ($recent->isEmpty())<div class="empty-state" style="border:0">Henüz test yapılmadı.</div>@else
        <div class="tw"><table class="t"><thead><tr><th>Bağlantı</th><th>Sonuç</th><th>Süre</th><th>Zaman</th></tr></thead><tbody>
            @foreach ($recent as $log)<tr><td class="mono">{{ substr($log->provider, 7) }}</td><td><span class="pill {{ $log->ok ? 'g' : 'c' }} flat">{{ $log->ok ? 'çalışıyor' : 'hata' }}</span> <span class="small">{{ $log->error }}</span></td><td class="mono small">{{ $log->duration_ms }} ms</td><td class="small">{{ $log->created_at?->format('d.m.Y H:i') }}</td></tr>@endforeach
        </tbody></table></div>@endif
    </div>
@endsection
