{{-- Bağlantı durumu kartı (faz 60d): $status SearchPerformanceService::status(); $label; $syncRoute (isteğe bağlı). --}}
<div class="panel" style="margin-bottom:18px">
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
        <div>
            <p class="eyebrow">{{ $label }} bağlantısı</p>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <span class="badge badge--{{ $status['enabled'] ? 'ok' : 'muted' }}">env: {{ $status['enabled'] ? 'açık' : 'kapalı' }}</span>
                <span class="badge badge--{{ $status['secrets_ok'] ? 'ok' : 'muted' }}">servis hesabı: {{ $status['secrets_ok'] ? 'tanımlı' : 'yok' }}</span>
                <span class="badge badge--{{ $status['property'] !== '' ? 'ok' : 'muted' }}">mülk: {{ $status['property'] !== '' ? $status['property'] : 'seçilmedi' }}</span>
                @if ($status['verified'] !== null)<span class="badge badge--{{ $status['verified'] ? 'ok' : 'danger' }}">doğrulama: {{ $status['verified'] ? 'servis hesabı mülke erişiyor' : 'servis hesabı bu mülkü görmüyor' }}</span>@endif
                @if ($status['state'] !== null)
                    <span class="badge badge--{{ $status['state']->last_error === null ? 'ok' : 'danger' }}">son senkron: {{ $status['state']->last_success_at?->format('d.m.Y H:i') ?? '—' }}</span>
                @endif
            </div>
            @if ($status['state'] !== null && $status['state']->last_error !== null)<p class="small" style="margin:8px 0 0;color:var(--danger)">Son hata: {{ $status['state']->last_error }}</p>@endif
            @if ($status['steps'] !== [])
                <p class="small muted" style="margin:8px 0 0"><strong>Bağlamak için:</strong> {{ implode(' → ', $status['steps']) }}. Kimlik bilgisi yalnız env'de tutulur, panelde görünmez.</p>
            @endif
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="{{ route('panel.seo.settings.show', [$website, 'dogrulama']) }}" class="btn btn--ghost btn--pill">Mülk ayarı</a>
            <a href="{{ route('panel.settings.api') }}" class="btn btn--ghost btn--pill">API & entegrasyonlar</a>
            @if (isset($syncRoute) && $canSync)
                <form method="POST" action="{{ $syncRoute }}">@csrf<button type="submit" class="btn btn--brand btn--pill" @disabled(! $status['configured'])>Şimdi senkronla</button></form>
            @endif
        </div>
    </div>
</div>
