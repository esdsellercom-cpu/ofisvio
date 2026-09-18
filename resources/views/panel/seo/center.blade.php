@extends('layouts.panel')

@section('title', 'SEO & GEO Command Center — '.$website->name)

@php
    $tone = fn (?string $sev) => match ($sev) { 'critical', 'high' => 'danger', 'medium' => 'warn', 'low' => 'info', 'info' => 'muted', default => 'ok' };
    $kpiTone = fn (?string $sev) => match ($sev) { 'critical', 'high' => 'alert', 'medium', 'low' => 'watch', default => 'ok' };
    $q = fn (array $over) => route('panel.seo.center', $website).'?'.http_build_query(array_filter(array_merge($filters, $over), fn ($v) => $v !== '' && $v !== null));
@endphp

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.index') }}">SEO & GEO</a> · {{ $website->name }}</p>
            <h1 class="h2">Command Center</h1>
            <p>Her bulgu gerçek veriden her açılışta yeniden hesaplanır (içerik, hizmet, lokasyon, ayar, yönlendirme, şema çıktısı, performans baseline'ı). Dış servis çağrısı yok; bağlı olmayan entegrasyon "bağlı değil" olarak görünür — sahte metrik basılmaz.</p>
        </div>
        <div class="panel-head__actions">
            @if ($websites->count() > 1)
                <form method="GET" action="{{ route('panel.seo.center.home') }}" class="inline-form">
                    @foreach ($websites as $w)<a href="{{ route('panel.seo.center', $w) }}" class="btn btn--ghost btn--pill" @if ($w->id === $website->id) aria-current="page" @endif>{{ $w->name }}</a>@endforeach
                </form>
            @endif
            <a href="{{ route('panel.seo.schema', $website) }}" class="btn btn--ghost btn--pill">Schema Manager</a>
            <a href="{{ route('panel.seo.redirects.index', $website) }}" class="btn btn--ghost btn--pill">Yönlendirmeler & 404</a>
            <a href="{{ route('panel.seo.settings.show', $website) }}" class="btn btn--brand btn--pill">Gelişmiş ayarlar</a>
        </div>
    </div>

    <div class="kpis" style="margin-bottom:18px">
        <div class="kpi {{ $report['summary']['score'] >= 80 ? 'ok' : ($report['summary']['score'] >= 50 ? 'watch' : 'alert') }}"><span class="k">Sağlık puanı</span><span class="v">{{ $report['summary']['score'] }}</span><span class="d">100 − açık bulgu ağırlığı (kritik 15 · yüksek 8 · orta 4 · düşük 1)</span></div>
        <div class="kpi"><span class="k">Açık bulgu</span><span class="v">{{ $report['summary']['open'] }}</span><span class="d">{{ $report['summary']['autofixable'] }} tanesi otomatik düzeltilebilir (onaylı)</span></div>
        <div class="kpi"><span class="k">Yok sayılan</span><span class="v">{{ $report['summary']['ignored'] }}</span><span class="d"><a href="{{ $q(['durum' => 'ignored']) }}">listele</a></span></div>
        <div class="kpi"><span class="k">Çözüldü</span><span class="v">{{ $report['summary']['resolved'] }}</span><span class="d">yeniden görülen bulgu açık döner</span></div>
    </div>

    <div class="grid-auto" style="--min:150px;--gap:8px;margin-bottom:18px">
        @foreach ($report['categories'] as $key => $cat)
            <a href="{{ $q(['kategori' => $key, 'durum' => 'open']) }}" class="kpi {{ $kpiTone($cat['worst']) }}" style="border:1px solid var(--line);border-radius:var(--r);text-decoration:none">
                <span class="k">{{ $cat['label'] }}</span>
                <span class="v" style="font-size:18px">{{ $cat['open'] === 0 ? '✓' : $cat['open'] }}</span>
                <span class="d">{{ $cat['open'] === 0 ? 'sorun yok' : $severities[$cat['worst']] }}@if ($cat['ignored'] > 0) · {{ $cat['ignored'] }} yok sayıldı @endif</span>
            </a>
        @endforeach
    </div>

    <div class="note" style="margin-bottom:18px">
        <strong>Entegrasyonlar:</strong>
        @foreach ($report['integrations'] as $provider => $state)
            <span class="badge badge--{{ $state['connected'] ? 'ok' : 'muted' }}" style="margin-right:6px">{{ $state['note'] }}</span>
        @endforeach
        <span class="small muted">— bağlı olmayan sağlayıcı için veri gösterilmez; env ile açılır (<a href="{{ route('panel.settings.api') }}">API & entegrasyonlar</a>).</span>
    </div>

    @if (! $canCritical && $canRequestJit)
        <details class="panel" style="margin-bottom:18px">
            <summary class="small" style="cursor:pointer;color:var(--brand);font-weight:600">Kritik düzeltmeler (canonical, sitemap) için JIT erişimi iste</summary>
            <form method="POST" action="{{ route('panel.seo.jit', [$website, 'settings']) }}" class="stack" style="gap:10px;margin-top:10px;max-width:480px">
                @csrf
                <label class="field"><span class="label">Gerekçe</span><textarea class="control" name="reason" required minlength="10" maxlength="500" style="min-height:64px"></textarea></label>
                <div class="inline-form">
                    <label class="field" style="flex:0 1 140px"><span class="label">Süre (dk)</span><input class="control" type="number" name="ttl_minutes" value="{{ $defaultTtl }}" min="5" max="{{ $maxTtl }}" required></label>
                    <button type="submit" class="btn btn--brand">Erişim aç</button>
                </div>
            </form>
        </details>
    @endif

    <form method="GET" action="{{ route('panel.seo.center', $website) }}" class="inline-form" style="margin-bottom:12px;flex-wrap:wrap;gap:8px">
        <select class="control" name="kategori" style="max-width:220px">
            <option value="">Tüm kategoriler</option>
            @foreach ($categories as $key => $label)<option value="{{ $key }}" @selected($filters['kategori'] === $key)>{{ $label }}</option>@endforeach
        </select>
        <select class="control" name="onem" style="max-width:160px">
            <option value="">Tüm önem</option>
            @foreach ($severities as $key => $label)<option value="{{ $key }}" @selected($filters['onem'] === $key)>{{ $label }}</option>@endforeach
        </select>
        <select class="control" name="durum" style="max-width:160px">
            <option value="open" @selected($filters['durum'] === 'open')>Açık</option>
            <option value="ignored" @selected($filters['durum'] === 'ignored')>Yok sayılan</option>
            <option value="all" @selected($filters['durum'] === 'all')>Hepsi</option>
        </select>
        <button type="submit" class="btn btn--ghost btn--pill">Süz</button>
        <span class="small muted">{{ count($issues) }} bulgu</span>
    </form>

    @if ($issues === [])
        <div class="panel"><p class="body-muted" style="margin:0">Bu süzgeçte bulgu yok.</p></div>
    @else
        <div class="table-wrap">
            <table class="data" data-seo-issues>
                <thead><tr><th>Bulgu</th><th>Önem</th><th>Adres</th><th>Neden → Öneri</th><th>Otomatik düzeltme</th><th>Durum</th></tr></thead>
                <tbody>
                    @foreach ($issues as $issue)
                        <tr>
                            <td style="max-width:320px"><strong>{{ $issue['title'] }}</strong><span class="small muted" style="display:block">{{ $categories[$issue['category']] }}</span>@if ($issue['note'])<span class="small" style="display:block;color:var(--ink-2)">Not: {{ $issue['note'] }}</span>@endif</td>
                            <td><span class="badge badge--{{ $tone($issue['severity']) }}">{{ $severities[$issue['severity']] }}</span></td>
                            <td class="mono small">@if ($issue['url'])<a href="{{ $website->baseUrl().$issue['url'] }}" target="_blank" rel="noopener">{{ $issue['url'] }}</a>@else —@endif</td>
                            <td style="max-width:360px;font-size:13px"><div>{{ $issue['cause'] }}</div><div style="color:var(--brand);margin-top:4px">→ {{ $issue['recommendation'] }}</div></td>
                            <td style="font-size:13px">
                                @if ($issue['fix'] === null)
                                    <span class="muted">Hayır — elle</span>
                                @elseif ($issue['status'] === 'ignored')
                                    <span class="muted">{{ $issue['fix_label'] }}</span>
                                @else
                                    @php($critical = $issue['fix_mode'] === 'critical')
                                    @if (($critical && $canCritical) || (! $critical && $canEdit))
                                        <form method="POST" action="{{ $critical ? route('panel.seo.center.fix-critical', $website) : route('panel.seo.center.fix', $website) }}" class="stack" style="gap:4px">
                                            @csrf
                                            <input type="hidden" name="key" value="{{ $issue['key'] }}">
                                            <label class="checkbox-row small"><input type="checkbox" name="confirm" value="1" required> <span>Onaylıyorum</span></label>
                                            <button type="submit" class="btn btn--brand btn--pill">{{ $issue['fix_label'] }}</button>
                                        </form>
                                    @else
                                        <span class="badge badge--info">{{ $issue['fix_label'] }}</span>
                                        <span class="small muted" style="display:block">Onay gerekli: {{ $critical ? 'seo.settings + JIT' : 'seo.edit' }}</span>
                                    @endif
                                @endif
                            </td>
                            <td style="font-size:13px">
                                <span class="badge badge--{{ $issue['status'] === 'ignored' ? 'muted' : 'warn' }}">{{ $issue['status'] === 'ignored' ? 'Yok sayıldı' : ($issue['reopened'] ? 'Yeniden açıldı' : 'Açık') }}</span>
                                @if ($canEdit)
                                    <details style="margin-top:6px">
                                        <summary class="small" style="cursor:pointer;color:var(--brand)">Karar</summary>
                                        <form method="POST" action="{{ route('panel.seo.center.decide', $website) }}" class="stack" style="gap:6px;margin-top:6px;min-width:180px">
                                            @csrf
                                            <input type="hidden" name="key" value="{{ $issue['key'] }}">
                                            <input class="control" type="text" name="note" maxlength="500" placeholder="Not (isteğe bağlı)" value="{{ $issue['note'] }}">
                                            <div class="inline-form" style="gap:4px;flex-wrap:wrap">
                                                @if ($issue['status'] !== 'ignored')<button type="submit" name="status" value="ignored" class="btn btn--ghost btn--pill">Yok say</button>@endif
                                                <button type="submit" name="status" value="resolved" class="btn btn--ghost btn--pill">Çözüldü</button>
                                                @if ($issue['status'] === 'ignored')<button type="submit" name="status" value="open" class="btn btn--ghost btn--pill">Yeniden aç</button>@endif
                                            </div>
                                        </form>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
