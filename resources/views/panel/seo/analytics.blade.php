@extends('layouts.panel')

@section('title', 'Analytics — '.$website->name)

@section('content')
    @php($kinds = ['home' => 'Ana sayfa', 'service' => 'Hizmet sayfaları', 'location' => 'Lokasyon sayfaları', 'landing' => 'Hizmet × şehir', 'blog' => 'Blog', 'page' => 'Sayfalar'])
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.center', $website) }}">Command Center</a> · {{ $website->name }}</p>
            <h1 class="h2">Analytics — organik trafik</h1>
            <p>GA4 Data API'den yalnız <strong>Organic Search</strong> kanalı: oturum, kullanıcı, etkileşim, dönüşüm (key events); açılış sayfaları hizmet / lokasyon / blog bazında; organik huni. Veri Gateway üzerinden servis hesabıyla çekilir; bağlı değilse rakam basılmaz.</p>
        </div>
        <div class="panel-head__actions">
            @foreach ([7, 28, 90] as $d)<a href="{{ route('panel.seo.analytics', [$website, 'gun' => $d]) }}" class="btn btn--ghost btn--pill" @if ($days === $d) aria-current="page" @endif>Son {{ $d }} gün</a>@endforeach
        </div>
    </div>

    @include('panel.seo.partials.connection', ['label' => 'Analytics (GA4)', 'syncRoute' => route('panel.seo.sync', [$website, 'analytics'])])

    @if ($summary === null)
        <div class="panel"><p class="body-muted" style="margin:0">Henüz veri yok. Bağlantı tamamlanınca ilk senkron ({{ 'php artisan ofisvio:analytics-sync' }}) organik trafik verisini getirir; zamanlayıcı her gece yeniler.</p></div>
    @else
        @php($t = $summary['totals'])
        <div class="kpis kpis--6" style="margin-bottom:18px">
            <div class="kpi"><span class="k">Organik oturum</span><span class="v">{{ number_format($t['sessions'], 0, ',', '.') }}</span><span class="d">{{ $summary['range'][0] }} – {{ $summary['range'][1] }}</span></div>
            <div class="kpi"><span class="k">Kullanıcı</span><span class="v">{{ number_format($t['users'], 0, ',', '.') }}</span><span class="d">toplam</span></div>
            <div class="kpi"><span class="k">Etkileşimli</span><span class="v">{{ number_format($t['engaged'], 0, ',', '.') }}</span><span class="d">%{{ $t['engagement_rate'] }} etkileşim</span></div>
            <div class="kpi"><span class="k">Dönüşüm</span><span class="v">{{ $t['conversions'] }}</span><span class="d">%{{ $t['conversion_rate'] }} oran</span></div>
            <div class="kpi"><span class="k">Hizmet dönüşümü</span><span class="v">{{ $summary['by_kind']['service']['conversions'] ?? 0 }}</span><span class="d">{{ $summary['by_kind']['service']['sessions'] ?? 0 }} oturum</span></div>
            <div class="kpi"><span class="k">Lokasyon dönüşümü</span><span class="v">{{ $summary['by_kind']['location']['conversions'] ?? 0 }}</span><span class="d">{{ $summary['by_kind']['location']['sessions'] ?? 0 }} oturum</span></div>
        </div>

        <div class="grid-auto" style="--min:320px;--gap:18px;align-items:start;margin-bottom:18px">
            <div class="panel">
                <p class="eyebrow">Organik huni</p>
                @php($f = $summary['funnel'])
                @foreach (['sessions' => 'Oturum', 'engaged' => 'Etkileşimli oturum', 'conversions' => 'Dönüşüm'] as $key => $label)
                    <div style="margin-bottom:8px"><div class="small" style="display:flex;justify-content:space-between"><span>{{ $label }}</span><span class="mono">{{ $f[$key] }}</span></div><div style="height:10px;background:var(--surface-2);border-radius:5px;overflow:hidden"><div style="height:100%;width:{{ $f['sessions'] > 0 ? (int) round($f[$key] / $f['sessions'] * 100) : 0 }}%;background:var(--brand)"></div></div></div>
                @endforeach
            </div>
            <div class="panel">
                <p class="eyebrow">Sayfa türüne göre</p>
                <table class="data"><thead><tr><th>Tür</th><th>Sayfa</th><th>Oturum</th><th>Etkileşimli</th><th>Dönüşüm</th></tr></thead><tbody>
                    @foreach ($kinds as $key => $label)@if (isset($summary['by_kind'][$key]))<tr><td>{{ $label }}</td><td class="mono">{{ $summary['by_kind'][$key]['pages'] }}</td><td class="mono">{{ $summary['by_kind'][$key]['sessions'] }}</td><td class="mono">{{ $summary['by_kind'][$key]['engaged'] }}</td><td class="mono">{{ $summary['by_kind'][$key]['conversions'] }}</td></tr>@endif @endforeach
                </tbody></table>
            </div>
        </div>

        <div class="panel" style="margin-bottom:18px">
            <p class="eyebrow">Günlük organik oturum</p>
            <div style="display:flex;gap:2px;align-items:flex-end;height:120px;overflow:hidden">
                @php($max = max(1, collect($summary['daily'])->max('sessions')))
                @foreach ($summary['daily'] as $day)<div title="{{ $day->date }}: {{ $day->sessions }} oturum · {{ $day->conversions }} dönüşüm" style="flex:1;background:var(--brand);height:{{ (int) round($day->sessions / $max * 100) }}%;min-height:2px;border-radius:2px 2px 0 0"></div>@endforeach
            </div>
        </div>

        <div class="grid-auto" style="--min:320px;--gap:18px;align-items:start">
            <div class="panel"><p class="eyebrow">Açılış sayfaları</p><table class="data"><thead><tr><th>Sayfa</th><th>Oturum</th><th>Etkileşim</th><th>Dönüşüm</th></tr></thead><tbody>@foreach ($summary['landing'] as $r)<tr><td class="mono small">{{ $r->key }}</td><td class="mono">{{ $r->sessions }}</td><td class="mono">%{{ round($r->engagement_rate * 100) }}</td><td class="mono">{{ $r->conversions }}</td></tr>@endforeach</tbody></table></div>
            <div class="stack" style="gap:18px">
                <div class="panel"><p class="eyebrow">Cihazlar</p><table class="data"><thead><tr><th>Cihaz</th><th>Oturum</th><th>Dönüşüm</th></tr></thead><tbody>@foreach ($summary['devices'] as $r)<tr><td>{{ ucfirst($r->key) }}</td><td class="mono">{{ $r->sessions }}</td><td class="mono">{{ $r->conversions }}</td></tr>@endforeach</tbody></table></div>
                <div class="panel"><p class="eyebrow">Kaynaklar</p><table class="data"><thead><tr><th>Kaynak</th><th>Oturum</th></tr></thead><tbody>@foreach ($summary['sources'] as $r)<tr><td>{{ $r->key }}</td><td class="mono">{{ $r->sessions }}</td></tr>@endforeach</tbody></table></div>
            </div>
        </div>
    @endif
@endsection
