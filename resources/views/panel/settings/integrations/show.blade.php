@extends('layouts.panel')

@section('title', $def['label'].' — Entegrasyon')

@php($tone = ['connected' => 'ok', 'unconfigured' => 'warn', 'error' => 'danger', 'disabled' => 'muted', 'link' => 'info'][$status['state']])
@php($basic = array_values(array_filter($fields, fn ($f) => empty($f['advanced']))))
@php($advanced = array_values(array_filter($fields, fn ($f) => ! empty($f['advanced']))))

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.settings.api') }}">Entegrasyon merkezi</a> · {{ $category['label'] }}</p>
            <h1 class="h2">{{ $def['label'] }}</h1>
            <p>{{ $def['description'] }} <span class="muted">Kullanım: {{ $def['usage'] }}</span></p>
        </div>
        <div class="panel-head__actions">
            <span class="badge badge--{{ $tone }}">{{ $status['state_label'] }}</span>
            @if ($status['last_ok_at'])<span class="small muted">son başarılı: {{ \Illuminate\Support\Carbon::parse($status['last_ok_at'])->diffForHumans() }}</span>@endif
        </div>
    </div>

    @if (session('test_error'))<div class="notice notice--error" role="alert" style="margin-bottom:16px"><span class="notice__dot" aria-hidden="true"></span><div>{{ session('test_error') }}</div></div>@endif

    @if ($def['kind'] === 'link')
        <div class="panel">
            <p style="margin:0 0 12px">Bu entegrasyon başka bir ekranda yönetilir.</p>
            @if (($def['link'] ?? '') === 'webhooks')
                <a href="{{ route('panel.settings.webhooks.index') }}" class="btn btn--brand">Webhook merkezi</a>
                <a href="{{ route('panel.settings.integrations.health') }}" class="btn btn--ghost">Gelen webhook olayları</a>
            @elseif (($def['link'] ?? '') === 'chrome')
                <a href="{{ route('panel.settings.chrome.header') }}" class="btn btn--brand">Header & footer (sosyal bağlantılar)</a>
                <a href="{{ route('panel.seo.settings.home', ['sekme' => 'varlik']) }}" class="btn btn--ghost">GEO varlık (sameAs)</a>
            @endif
        </div>
    @else
        <div class="grid-auto" style="--min:340px;--gap:18px;align-items:start">
            <form method="POST" action="{{ route('panel.settings.integrations.save', $key) }}" class="panel stack" style="gap:12px">
                @csrf
                <p class="eyebrow" style="margin:0">1. Bilgileri girin</p>
                @foreach ($basic as $f)
                    @include('panel.settings.integrations.partials.field', ['f' => $f])
                @endforeach
                @if ($advanced !== [])
                    <details><summary class="small" style="cursor:pointer;color:var(--brand);font-weight:600">Gelişmiş ayarlar</summary>
                        <div class="stack" style="gap:10px;margin-top:10px">@foreach ($advanced as $f)@include('panel.settings.integrations.partials.field', ['f' => $f])@endforeach</div>
                    </details>
                @endif
                <p class="eyebrow" style="margin:8px 0 0">3. Aktifleştirin</p>
                <div style="display:flex;gap:14px;flex-wrap:wrap">
                    <label class="checkbox-row"><input type="radio" name="enabled_choice" value="on" @checked($status['enabled'] && $status['source'] === 'panel')><span>Aktif</span></label>
                    <label class="checkbox-row"><input type="radio" name="enabled_choice" value="off" @checked(! $status['enabled'] && $status['source'] === 'panel')><span>Pasif</span></label>
                    <label class="checkbox-row"><input type="radio" name="enabled_choice" value="env" @checked($status['source'] !== 'panel')><span>Env'e bırak (şu an {{ $status['enabled'] ? 'aktif' : 'pasif' }})</span></label>
                </div>
                @if ($canManage)
                    <div><button type="submit" class="btn btn--brand">Kaydet</button> <span class="small muted">Secret girişi için {{ $canSecrets ? 'yetkiniz var' : 'secrets.manage gerekir' }}.</span></div>
                @else
                    <p class="small muted" style="margin:0">Düzenlemek için integrations.manage gerekir; salt okunur.</p>
                @endif
            </form>

            <div class="stack" style="gap:18px">
                <div class="panel">
                    <p class="eyebrow">2. Bağlantıyı test edin</p>
                    <p class="small muted" style="margin:0 0 10px">Gerçek bir istek yapılır (Gateway üzerinden, loglanır; secret loga girmez). Sonuç: bağlantı başarılı / başarısız + neden.</p>
                    @if ($canManage)
                        <form method="POST" action="{{ route('panel.settings.integrations.test', $key) }}">@csrf<button type="submit" class="btn btn--ghost" @disabled($status['missing'] !== [])>Bağlantıyı test et</button></form>
                        @if ($status['missing'] !== [])<p class="small muted" style="margin:8px 0 0">Önce eksik alanlar: {{ implode(', ', $status['missing']) }}</p>@endif
                    @endif
                </div>
                <div class="panel">
                    <p class="eyebrow">Son istekler</p>
                    @if ($recent->isEmpty())<p class="body-muted small" style="margin:0">Henüz istek yok.</p>@else
                        <table class="data"><thead><tr><th>Zaman</th><th>Yöntem</th><th>Yol</th><th>Durum</th><th>ms</th></tr></thead><tbody>
                            @foreach ($recent as $log)<tr><td class="small">{{ $log->created_at->format('d.m H:i') }}</td><td class="mono small">{{ $log->method }}</td><td class="mono small">{{ $log->path }}</td><td><span class="badge badge--{{ $log->ok ? 'ok' : 'danger' }}">{{ $log->status ?? ($log->ok ? 'ok' : 'hata') }}</span>@if ($log->error)<span class="small muted" style="display:block">{{ $log->error }}</span>@endif</td><td class="mono small">{{ $log->duration_ms }}</td></tr>@endforeach
                        </tbody></table>
                    @endif
                </div>
            </div>
        </div>
    @endif
@endsection
