@extends('layouts.panel')

@section('title', 'API & Entegrasyonlar')

{{-- API & Entegrasyon merkezi (faz 52): kategori bazlı; secret'lar env'de (maskeli), forma girilmez; Test bağlantısı gerçek yoklama. --}}
@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Ayar merkezi · ortam: <b>{{ $environment }}</b></p>
            <h1 class="h2">API &amp; Entegrasyonlar</h1>
            <p>Projede gerçekten kullanılan bağlantılar. Anahtar ve secret'lar <b>yalnız sunucu ortamında (.env)</b> tutulur; burada maskeli durum ve env adı görünür, değer hiçbir zaman tarayıcıya gönderilmez.</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.settings.health') }}" class="btn btn--ghost">Sistem sağlığı</a>
            <a href="{{ route('panel.integrations.index') }}" class="btn btn--ghost">Entegrasyon günlüğü</a>
        </div>
    </div>

    @if ($result)
        <div class="notice {{ $result['level'] === 'fail' ? 'notice--error' : '' }}" role="status" style="margin-bottom:16px"><span class="notice__dot" aria-hidden="true"></span><div><b>{{ $result['key'] }}</b> — {{ ['ok' => '✓ Çalışıyor', 'warn' => '⚠ Uyarı', 'fail' => '✕ Hata'][$result['level']] }}: {{ $result['note'] }}</div></div>
    @endif

    @php($pill = fn (?string $level) => match ($level) { 'ok' => 'g', 'warn' => 'w', 'fail' => 'c', default => 'n' })
    @php($levelOf = fn ($log) => $log === null ? null : ($log->ok ? 'ok' : 'fail'))

    <div class="stack" style="gap:18px">
        @foreach ($categories as $catKey => $catLabel)
            @php($items = array_filter($providers, fn ($p) => $p['category'] === $catKey))
            @php($cores = array_filter($core, fn ($c) => $c['category'] === $catKey))
            @continue($items === [] && $cores === [])
            <div class="card">
                <div class="card__head"><h3>{{ $catLabel }}</h3></div>
                <div class="rows">
                    @foreach ($cores as $c)
                        <div class="row" style="align-items:flex-start">
                            <div class="main-t"><b>{{ $c['label'] }}</b><span>Geçerli: <span class="mono">{{ $c['current'] }}</span> · env: <span class="mono muted">{{ implode(', ', $c['env']) ?: '—' }}</span></span>
                                @if ($c['last'])<span class="small">Son test {{ $c['last']->created_at?->format('d.m.Y H:i') }}: <span class="pill {{ $pill($levelOf($c['last'])) }} flat">{{ $c['last']->ok ? 'çalışıyor' : 'hata' }}</span> {{ $c['last']->error }}</span>@endif</div>
                            <span class="rt">@can('settings.manage')<form method="POST" action="{{ route('panel.settings.api.test', $c['key']) }}">@csrf<button type="submit" class="btn btn--ghost btn--pill">Bağlantıyı test et</button></form>@endcan</span>
                        </div>
                    @endforeach
                    @foreach ($items as $p)
                        <div class="row" style="align-items:flex-start">
                            <div class="main-t">
                                <b>{{ $p['label'] }} <span class="pill {{ $p['enabled'] ? ($p['missing'] === [] ? 'g' : 'c') : 'n' }} flat">{{ $p['enabled'] ? ($p['missing'] === [] ? 'aktif' : 'aktif · eksik secret') : 'pasif' }}</span>@if ($p['webhook']) <span class="pill n flat">webhook imzalı</span>@endif</b>
                                <span>Endpoint: <span class="mono">{{ $p['endpoint'] ?: '—' }}</span> · Ortam: {{ $environment }}</span>
                                <span>Secret'lar: @forelse ($p['secrets'] as $name => $masked)<span class="mono">{{ $name }}={{ $masked ?: '••••••••' }}</span>@if (! $loop->last), @endif @empty <span class="muted">yok</span> @endforelse @if ($p['missing'] !== []) · <span style="color:var(--danger)">eksik: {{ implode(', ', $p['missing']) }}</span>@endif</span>
                                <span class="small muted">{{ $p['usage'] }} · env: <span class="mono">{{ implode(', ', $p['env']) }}</span></span>
                                @if ($p['last'])<span class="small">Son test {{ $p['last']->created_at?->format('d.m.Y H:i') }}: <span class="pill {{ $pill($levelOf($p['last'])) }} flat">{{ $p['last']->ok ? 'çalışıyor' : 'hata' }}</span> {{ $p['last']->error }}</span>@endif
                            </div>
                            <span class="rt">@can('settings.manage')<form method="POST" action="{{ route('panel.settings.api.test', $p['key']) }}">@csrf<button type="submit" class="btn btn--ghost btn--pill">Bağlantıyı test et</button></form>@endcan</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="card"><div class="card__head"><h3>Kullanılmayan servisler</h3></div><div class="card__body small muted">Harita (Google Haritalar gömme anahtar istemez; içerik bloğu <code>[embed:https://www.google.com/maps/embed…]</code>), Mapbox, S3/bulut depolama (yerel disk kullanılır; <code>FILESYSTEM_DISK</code> ile değiştirilir), OAuth ve dış REST API bu sürümde kullanılmaz; bu yüzden burada sahte alan listelenmez. Yeni sağlayıcı = <code>config/integrations.php</code> satırı + Gateway.</div></div>
    </div>
@endsection
