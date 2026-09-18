@extends('layouts.panel')

@section('title', 'GEO Manager — '.$website->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.center', $website) }}">Command Center</a> · {{ $website->name }}</p>
            <h1 class="h2">GEO Manager — üretken arama motorları</h1>
            <p>Klasik SEO'dan farkı: ChatGPT, Perplexity, Gemini gibi sistemlerin Ofisvio'yu doğru anlaması. Kaynaklar: marka/varlık bilgisi (Organization), hizmet başına yapılandırılmış cevaplar (GEO Answer Engine), lokasyon künyeleri (LocalBusiness), SSS, llms.txt ve içerik güncelliği. Her şey veritabanından; uydurma bilgi yok.</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.seo.settings.show', [$website, 'geo']) }}" class="btn btn--brand btn--pill">GEO ayarları (marka tanımı, llms.txt, SSS)</a>
            <a href="{{ route('panel.seo.entities', $website) }}" class="btn btn--ghost btn--pill">Knowledge Graph</a>
            @if ($settings['geo.llms_enabled'])<a href="{{ $website->baseUrl() }}/llms.txt" class="btn btn--ghost btn--pill" target="_blank" rel="noopener">llms.txt ↗</a>@endif
        </div>
    </div>

    <div class="kpis" style="margin-bottom:18px">
        <div class="kpi {{ trim((string) $settings['geo.brand_definition']) !== '' ? 'ok' : 'alert' }}"><span class="k">Marka tanımı</span><span class="v" style="font-size:16px">{{ trim((string) $settings['geo.brand_definition']) !== '' ? 'var' : 'yok' }}</span><span class="d">Organization description + llms.txt</span></div>
        <div class="kpi {{ $settings['geo.llms_enabled'] && $settings['crawl.ai_crawlers_allowed'] ? 'ok' : 'watch' }}"><span class="k">llms.txt / AI botları</span><span class="v" style="font-size:16px">{{ $settings['geo.llms_enabled'] ? 'açık' : 'kapalı' }} / {{ $settings['crawl.ai_crawlers_allowed'] ? 'izinli' : 'engelli' }}</span><span class="d">GEO için ikisi de açık olmalı</span></div>
        <div class="kpi {{ count((array) $settings['geo.faq']) >= 2 ? 'ok' : 'watch' }}"><span class="k">GEO SSS</span><span class="v">{{ count((array) $settings['geo.faq']) }}</span><span class="d">ana sayfa FAQPage (≥ 2)</span></div>
        <div class="kpi {{ $services->where('filled', '>=', 6)->count() === $services->count() && $services->isNotEmpty() ? 'ok' : 'watch' }}"><span class="k">Cevaplı hizmet</span><span class="v">{{ $services->where('filled', '>', 0)->count() }}/{{ $services->count() }}</span><span class="d">GEO Answer Engine</span></div>
    </div>

    <div class="panel" style="margin-bottom:18px">
        <p class="eyebrow">GEO Answer Engine — hizmet başına yapılandırılmış cevaplar</p>
        <p class="small muted" style="margin:0 0 10px">Her hizmet için {{ count($fields) }} cevap alanı + SSS + ilgili hizmet/lokasyon: {{ implode(' · ', array_map(fn ($f) => $f[0], $fields)) }}. Hizmet formundan yönetilir; hizmet sayfasında başlıklı bölümler ve FAQPage şeması olarak basılır.</p>
        @if ($services->isEmpty())<p class="body-muted" style="margin:0">Hizmet yok.</p>@else
            <table class="data">
                <thead><tr><th>Hizmet</th><th>Doluluk</th><th>SSS</th><th>Eksik alanlar</th><th></th></tr></thead>
                <tbody>
                    @foreach ($services as $row)
                        @php($answers = is_array($row['model']->answers) ? $row['model']->answers : [])
                        @php($missing = array_values(array_filter(array_map(fn ($key, $def) => (is_array($answers[$key] ?? null) ? $answers[$key] === [] : trim((string) ($answers[$key] ?? '')) === '') ? $def[0] : null, array_keys($fields), $fields))))
                        <tr>
                            <td><strong>{{ $row['model']->name }}</strong>@if (! $row['model']->is_active) <span class="badge badge--muted">pasif</span>@endif</td>
                            <td><span class="badge badge--{{ $row['filled'] >= 6 ? 'ok' : ($row['filled'] > 0 ? 'warn' : 'danger') }}">{{ $row['filled'] }}/{{ $row['total'] }}</span></td>
                            <td class="mono">{{ $row['faq'] }}</td>
                            <td class="small muted">{{ implode(', ', $missing) ?: '—' }}</td>
                            <td><a href="{{ route('panel.services.edit', $row['model']) }}" class="btn btn--ghost btn--pill">Cevapları düzenle</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="grid-auto" style="--min:320px;--gap:18px;align-items:start">
        <div class="panel">
            <p class="eyebrow">Lokasyon varlık alanları (LocalBusiness)</p>
            @if ($geoAudit === [])<span class="badge badge--ok">Yayındaki tüm şubelerin künyesi tam</span>@else
                <ul style="margin:0;padding-left:18px">
                    @foreach ($geoAudit as $f)<li><a href="{{ route('panel.geo.edit', $f['location']) }}">{{ $f['location']->name }}</a>: <span class="small muted">{{ implode(' ', $f['issues']) }}</span></li>@endforeach
                </ul>
            @endif
            <p class="small muted" style="margin:10px 0 0">Adres, telefon, koordinat, çalışma saatleri, posta kodu ve açıklama LocalBusiness şemasına ve yerel aramaya girer.</p>
        </div>
        <div class="panel">
            <p class="eyebrow">Kurumsal bilgi · hizmet kapsamı · güvenilirlik</p>
            <dl class="dl">
                <dt>Uzmanlık</dt><dd class="small">{{ implode(', ', (array) $settings['geo.expertise']) ?: '—' }}</dd>
                <dt>Hizmet bölgeleri</dt><dd class="small">{{ implode(', ', (array) $settings['geo.locations_served']) ?: '—' }} · {{ implode(', ', (array) $settings['local.service_cities']) ?: '—' }}</dd>
                <dt>Hedef kitle</dt><dd class="small">{{ $settings['geo.audience'] ?: '—' }}</dd>
                <dt>Kaynaklar</dt><dd class="small">{{ count((array) $settings['geo.resources']) }} satır</dd>
                <dt>Öncelikli sayfalar</dt><dd class="small">{{ count((array) $settings['geo.priority_urls']) }} adres</dd>
            </dl>
            <p class="small muted" style="margin:10px 0 0">İçerik güncellik tarihi Article şemasında <code>dateModified</code> olarak her yazıdan otomatik gelir; eski içerikler İçerik Yenileme ekranında aday olur.</p>
        </div>
    </div>
@endsection
