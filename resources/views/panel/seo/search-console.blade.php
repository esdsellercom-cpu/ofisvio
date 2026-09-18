@extends('layouts.panel')

@section('title', 'Search Console — '.$website->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.center', $website) }}">Command Center</a> · {{ $website->name }}</p>
            <h1 class="h2">Search Console</h1>
            <p>Tıklama, gösterim, CTR ve ortalama sıra; sorgular, sayfalar, ülkeler, cihazlar; sitemap/indeksleme durumu ve içerik fırsatları. Veri Integration Gateway üzerinden servis hesabıyla günlük çekilir ve burada saklanan gerçek satırlar gösterilir. Bağlı değilse hiçbir rakam basılmaz.</p>
        </div>
        <div class="panel-head__actions">
            @foreach ([7, 28, 90] as $d)<a href="{{ route('panel.seo.search-console', [$website, 'gun' => $d]) }}" class="btn btn--ghost btn--pill" @if ($days === $d) aria-current="page" @endif>Son {{ $d }} gün</a>@endforeach
        </div>
    </div>

    @include('panel.seo.partials.connection', ['label' => 'Search Console', 'syncRoute' => route('panel.seo.sync', [$website, 'search_console'])])

    @if ($summary === null)
        <div class="panel"><p class="body-muted" style="margin:0">Henüz veri yok. Bağlantı tamamlanınca ilk senkron ({{ 'php artisan ofisvio:search-console-sync' }}) tıklama/gösterim verisini getirir; zamanlayıcı her gece yeniler.</p></div>
    @else
        @php($t = $summary['totals'])
        @php($p = $summary['previous'])
        @php($delta = fn ($now, $before) => $before > 0 ? round(($now - $before) / $before * 100) : null)
        <div class="kpis" style="margin-bottom:18px">
            <div class="kpi"><span class="k">Tıklama</span><span class="v">{{ number_format($t['clicks'], 0, ',', '.') }}</span><span class="d">@if ($delta($t['clicks'], $p['clicks']) !== null){{ $delta($t['clicks'], $p['clicks']) >= 0 ? '▲' : '▼' }} %{{ abs($delta($t['clicks'], $p['clicks'])) }} önceki döneme göre @else önceki dönem verisi yok @endif</span></div>
            <div class="kpi"><span class="k">Gösterim</span><span class="v">{{ number_format($t['impressions'], 0, ',', '.') }}</span><span class="d">@if ($delta($t['impressions'], $p['impressions']) !== null){{ $delta($t['impressions'], $p['impressions']) >= 0 ? '▲' : '▼' }} %{{ abs($delta($t['impressions'], $p['impressions'])) }}@endif</span></div>
            <div class="kpi"><span class="k">CTR</span><span class="v">%{{ $t['ctr'] }}</span><span class="d">tıklama / gösterim</span></div>
            <div class="kpi"><span class="k">Ort. sıra</span><span class="v">{{ $t['position'] ?? '—' }}</span><span class="d">@if ($p['position'] !== null) önceki {{ $p['position'] }} @endif</span></div>
        </div>
        <p class="small muted" style="margin:0 0 18px">Dönem {{ $summary['range'][0] }} – {{ $summary['range'][1] }} (GSC verisi ~2 gün gecikmeli) · boyut tabloları {{ $summary['latest'] }} tarihli senkrondan.</p>

        <div class="panel" style="margin-bottom:18px">
            <p class="eyebrow">Günlük tıklama / gösterim</p>
            <div style="display:flex;gap:2px;align-items:flex-end;height:120px;overflow:hidden">
                @php($max = max(1, collect($summary['daily'])->max('clicks')))
                @foreach ($summary['daily'] as $day)
                    <div title="{{ $day->date }}: {{ $day->clicks }} tıklama · {{ $day->impressions }} gösterim" style="flex:1;background:var(--brand);height:{{ (int) round($day->clicks / $max * 100) }}%;min-height:2px;border-radius:2px 2px 0 0"></div>
                @endforeach
            </div>
        </div>

        <div class="grid-auto" style="--min:320px;--gap:18px;align-items:start;margin-bottom:18px">
            <div class="panel">
                <p class="eyebrow">İçerik fırsatları — sıra 8–20, ≥ 50 gösterim</p>
                @if ($summary['opportunities'] === [])<p class="body-muted small" style="margin:0">Bu dönemde fırsat kriterine uyan sorgu yok.</p>@else
                    <table class="data"><thead><tr><th>Sorgu</th><th>Gösterim</th><th>Tıklama</th><th>Sıra</th></tr></thead><tbody>
                        @foreach ($summary['opportunities'] as $q)<tr><td>{{ $q->key }}</td><td class="mono">{{ $q->impressions }}</td><td class="mono">{{ $q->clicks }}</td><td class="mono">{{ $q->position }}</td></tr>@endforeach
                    </tbody></table>
                    <p class="small muted" style="margin:8px 0 0">Bu sorgular için hedef sayfanın başlığını/açıklamasını güçlendirin ya da Keyword Intelligence'ta kelimeyi sayfaya eşleyin.</p>
                @endif
            </div>
            <div class="panel">
                <p class="eyebrow">Sitemap & indeksleme (GSC)</p>
                @php($sitemaps = (array) ($status['state']?->meta['sitemaps'] ?? []))
                @if ($sitemaps === [])<p class="body-muted small" style="margin:0">Gönderilmiş sitemap görünmüyor; Search Console'da sitemap.xml adresini gönderin.</p>@else
                    <table class="data"><thead><tr><th>Sitemap</th><th>Gönderilen</th><th>İndekslenen</th><th>Hata / uyarı</th><th>Son okuma</th></tr></thead><tbody>
                        @foreach ($sitemaps as $sm)<tr><td class="mono small">{{ $sm['path'] }}</td><td class="mono">{{ $sm['submitted'] }}</td><td class="mono">{{ $sm['indexed'] }}</td><td class="mono {{ ($sm['errors'] ?? 0) > 0 ? 'text-danger' : '' }}">{{ $sm['errors'] }} / {{ $sm['warnings'] }}</td><td class="small">{{ $sm['lastDownloaded'] ? substr($sm['lastDownloaded'], 0, 10) : '—' }}{{ $sm['isPending'] ? ' (bekliyor)' : '' }}</td></tr>@endforeach
                    </tbody></table>
                @endif
            </div>
        </div>

        <div class="grid-auto" style="--min:320px;--gap:18px;align-items:start">
            <div class="panel"><p class="eyebrow">Sorgular</p><table class="data"><thead><tr><th>Sorgu</th><th>Tık.</th><th>Göst.</th><th>CTR</th><th>Sıra</th></tr></thead><tbody>@foreach ($summary['queries'] as $r)<tr><td>{{ $r->key }}</td><td class="mono">{{ $r->clicks }}</td><td class="mono">{{ $r->impressions }}</td><td class="mono">%{{ round($r->ctr * 100, 1) }}</td><td class="mono">{{ $r->position }}</td></tr>@endforeach</tbody></table></div>
            <div class="panel"><p class="eyebrow">Sayfalar</p><table class="data"><thead><tr><th>Sayfa</th><th>Tık.</th><th>Göst.</th><th>Sıra</th></tr></thead><tbody>@foreach ($summary['pages'] as $r)<tr><td class="mono small">{{ parse_url((string) $r->key, PHP_URL_PATH) ?: $r->key }}</td><td class="mono">{{ $r->clicks }}</td><td class="mono">{{ $r->impressions }}</td><td class="mono">{{ $r->position }}</td></tr>@endforeach</tbody></table></div>
            <div class="panel"><p class="eyebrow">Ülkeler</p><table class="data"><thead><tr><th>Ülke</th><th>Tık.</th><th>Göst.</th></tr></thead><tbody>@foreach ($summary['countries'] as $r)<tr><td class="mono">{{ strtoupper($r->key) }}</td><td class="mono">{{ $r->clicks }}</td><td class="mono">{{ $r->impressions }}</td></tr>@endforeach</tbody></table></div>
            <div class="panel"><p class="eyebrow">Cihazlar</p><table class="data"><thead><tr><th>Cihaz</th><th>Tık.</th><th>Göst.</th><th>Sıra</th></tr></thead><tbody>@foreach ($summary['devices'] as $r)<tr><td>{{ ucfirst(strtolower($r->key)) }}</td><td class="mono">{{ $r->clicks }}</td><td class="mono">{{ $r->impressions }}</td><td class="mono">{{ $r->position }}</td></tr>@endforeach</tbody></table></div>
        </div>
    @endif
@endsection
