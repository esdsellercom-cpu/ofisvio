@extends('layouts.panel')

@section('title', $endpoint ? $endpoint->name.' — Webhook' : 'Webhook oluştur')

@php($statusTone = ['success' => 'ok', 'failed' => 'danger', 'pending' => 'warn'])

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.settings.webhooks.index') }}">Webhook merkezi</a></p>
            <h1 class="h2">{{ $endpoint ? $endpoint->name : 'Webhook oluştur' }}</h1>
            <p>URL https olmalı ve genel bir ana bilgisayara çözümlenmeli (yerel/özel ağ reddedilir). Secret boş bırakılırsa üretilir ve yalnız bir kez gösterilir.</p>
        </div>
        @if ($endpoint)
            <div class="panel-head__actions">
                <span class="badge badge--{{ $endpoint->is_active ? 'ok' : 'muted' }}">{{ $endpoint->is_active ? 'Aktif' : 'Pasif' }}</span>
                <form method="POST" action="{{ route('panel.settings.webhooks.test', $endpoint) }}" style="display:inline">@csrf<button type="submit" class="btn btn--ghost btn--pill">Test gönder</button></form>
                <a href="{{ route('panel.settings.webhooks.deliveries', ['uc' => $endpoint->id]) }}" class="btn btn--ghost btn--pill">Teslimatlar</a>
            </div>
        @endif
    </div>

    @if (session('webhook_secret'))
        <div class="note w" style="margin-bottom:16px">
            <strong>İmza secret'ı (yalnız bu kez gösterilir):</strong> <code class="mono" style="user-select:all">{{ session('webhook_secret') }}</code>
            <p class="small" style="margin:6px 0 0">Alıcı sisteme şimdi girin; kapattıktan sonra tekrar görüntülenemez — gerekirse "Yeni secret üret" ile döndürün.</p>
        </div>
    @endif

    <div class="grid-auto" style="--min:340px;--gap:18px;align-items:start">
        <form method="POST" action="{{ $endpoint ? route('panel.settings.webhooks.update', $endpoint) : route('panel.settings.webhooks.store') }}" class="panel stack" style="gap:12px">
            @csrf
            <p class="eyebrow" style="margin:0">1. Uç nokta</p>
            <label class="field"><span class="label">Ad</span><input class="control" type="text" name="name" value="{{ old('name', $endpoint?->name) }}" maxlength="120" required></label>
            <label class="field"><span class="label">URL (https)</span><input class="control mono" type="url" name="url" value="{{ old('url', $endpoint?->url) }}" placeholder="https://ornek.com/webhooks/ofisvio" maxlength="500" required></label>
            <label class="field"><span class="label">Açıklama</span><input class="control" type="text" name="description" value="{{ old('description', $endpoint?->description) }}" maxlength="255"></label>
            <label class="field"><span class="label">Secret</span>
                @if ($endpoint)
                    <input class="control mono" type="password" name="secret" value="" placeholder="•••••••••••• (değiştirmek için yeni değer girin)" autocomplete="new-password">
                @else
                    <input class="control mono" type="password" name="secret" value="" placeholder="Boş bırakın → otomatik üretilir" autocomplete="new-password">
                @endif
            </label>
            @if ($endpoint)
                <label class="checkbox-row small"><input type="checkbox" name="rotate_secret" value="1"><span>Yeni secret üret (bir kez gösterilir)</span></label>
            @endif

            <p class="eyebrow" style="margin:8px 0 0">2. Olaylar</p>
            @php($selected = (array) old('events', $endpoint?->events ?? []))
            @foreach ($grouped as $group => $items)
                <div><p class="small muted" style="margin:0 0 4px;font-weight:600">{{ $group }}</p>
                    @foreach ($items as $key => $def)
                        <label class="checkbox-row"><input type="checkbox" name="events[]" value="{{ $key }}" @checked(in_array($key, $selected, true))><span>{{ $def['label'] }} <span class="mono small muted">{{ $key }}</span></span></label>
                    @endforeach
                </div>
            @endforeach

            <p class="eyebrow" style="margin:8px 0 0">3. Teslimat</p>
            <div class="grid-auto" style="--min:140px;--gap:10px">
                <label class="field"><span class="label">Yeniden deneme (0–{{ $retryMax }})</span><input class="control" type="number" name="retry_max" min="0" max="{{ $retryMax }}" value="{{ old('retry_max', $endpoint?->retry_max ?? 3) }}"></label>
                <label class="field"><span class="label">Zaman aşımı (1–{{ $timeoutMax }} sn)</span><input class="control" type="number" name="timeout_seconds" min="1" max="{{ $timeoutMax }}" value="{{ old('timeout_seconds', $endpoint?->timeout_seconds ?? 10) }}"></label>
            </div>
            <label class="checkbox-row"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $endpoint?->is_active ?? true))><span>Aktif (olaylar bu uca gönderilir)</span></label>
            <p class="small muted" style="margin:0">Yeniden deneme aralıkları: 1 dk, 5 dk, 30 dk, 2 sa, 12 sa. Yanıt 2xx değilse ya da bağlantı kurulamazsa deneme sayılır.</p>
            <div><button type="submit" class="btn btn--brand">{{ $endpoint ? 'Kaydet' : 'Oluştur' }}</button></div>
        </form>

        <div class="stack" style="gap:18px">
            @if ($endpoint)
                <div class="panel">
                    <p class="eyebrow">Son teslimatlar</p>
                    @if ($recent->isEmpty())<p class="body-muted small" style="margin:0">Henüz teslimat yok. "Test gönder" ile deneyin.</p>@else
                        <table class="data"><thead><tr><th>Olay</th><th>Durum</th><th>Deneme</th><th>Yanıt</th><th>Süre</th><th>Zaman</th></tr></thead><tbody>
                            @foreach ($recent as $d)<tr><td class="mono small">{{ $d->event }}</td><td><span class="badge badge--{{ $statusTone[$d->status] }}">{{ \App\Models\WebhookDelivery::STATUSES[$d->status] }}</span></td><td class="small">{{ $d->attempts }}</td><td class="mono small">{{ $d->response_status ?? '—' }}</td><td class="small">{{ $d->duration_ms !== null ? $d->duration_ms.' ms' : '—' }}</td><td class="small">{{ $d->created_at->format('d.m H:i') }}</td></tr>@endforeach
                        </tbody></table>
                    @endif
                </div>
                <div class="panel">
                    <p class="eyebrow">Tehlikeli bölge</p>
                    <form method="POST" action="{{ route('panel.settings.webhooks.destroy', $endpoint) }}" onsubmit="return confirm('Bu webhook ve teslimat logu silinecek. Devam edilsin mi?')">@csrf<button type="submit" class="btn btn--ghost btn--pill" style="color:var(--danger)">Webhook'u sil</button></form>
                </div>
            @endif
            <div class="panel">
                <p class="eyebrow">İmza doğrulama</p>
                <p class="small" style="margin:0 0 6px">Başlıklar: <span class="mono">X-Ofisvio-Event</span>, <span class="mono">X-Ofisvio-Delivery</span>, <span class="mono">X-Ofisvio-Timestamp</span>, <span class="mono">X-Ofisvio-Signature: t=&lt;zaman&gt;,v1=&lt;hmac&gt;</span>.</p>
                <p class="small muted" style="margin:0">Alıcı: <span class="mono">hmac_sha256(t + "." + ham_gövde, secret)</span> değerini <span class="mono">v1</span> ile sabit zamanlı karşılaştırır; 5 dakikadan eski zaman damgasını reddeder.</p>
            </div>
        </div>
    </div>
@endsection
