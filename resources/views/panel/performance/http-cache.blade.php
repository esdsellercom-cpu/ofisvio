@extends('layouts.panel')

@section('title', 'Cache politikaları & HTTP cache')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Performance Command Center</p>
            <h1 class="h2">Cache politikaları · purge · HTTP cache</h1>
            <p>Katmanlar: full-page/HTTP cache (misafire <span class="mono">public, max-age, s-maxage</span> + ETag/304; oturum açana <span class="mono">private, no-store</span>), uygulama önbelleği (SEO meta, şema, sitemap, GEO varlığı, blog/lokasyon/hizmet listeleri — hepsi site başına sürümlü anahtarda), config/route/view cache (deploy adımı). Geçersizleme kaskadı: hizmet/lokasyon/içerik/site değişince sürüm atlar → SEO meta, şema, GEO varlığı, iç bağlantı, ilgili lokasyon ve sayfa önbelleği tek adımda düşer; eski çıktı servis edilmez.</p>
        </div>
        <div class="panel-head__actions"><a href="{{ route('panel.cache.index') }}" class="btn btn--brand btn--pill">Cache Manager (ısıt / purge / anahtarlar / TTL)</a></div>
    </div>
    @include('panel.performance.partials.nav')
    <div class="table-wrap" style="margin-bottom:18px"><table class="data">
        <thead><tr><th>Site</th><th>Uygulama TTL</th><th>max-age</th><th>s-maxage</th><th>Sürüm</th><th>İsabet / ıskalama</th><th>Purge</th><th></th></tr></thead>
        <tbody>
            @foreach ($websites as $row)
                <tr><td><strong>{{ $row['website']->name }}</strong></td><td class="mono">{{ $row['ttl'] }} sn</td><td class="mono">{{ $row['max_age'] }} sn</td><td class="mono">{{ $row['s_maxage'] }} sn</td><td class="mono">v{{ $row['stats']['version'] }}</td><td class="mono">{{ $row['stats']['hits'] }} / {{ $row['stats']['misses'] }}@if ($row['stats']['hit_ratio'] !== null) (%{{ round($row['stats']['hit_ratio'] * 100) }})@endif</td><td class="mono">{{ $row['stats']['purges'] }}</td><td><a href="{{ route('panel.cache.index') }}" class="btn btn--ghost btn--pill">Politika / purge</a></td></tr>
            @endforeach
        </tbody>
    </table></div>
    <div class="panel">
        <p class="eyebrow">Geçersizleme kaskadı — son olaylar</p>
        @if ($events->isEmpty())<p class="body-muted small" style="margin:0">Olay yok.</p>@else
            <table class="data"><thead><tr><th>Zaman</th><th>Tetikleyici</th><th>Varlık</th><th>Adımlar</th><th>Sürüm</th></tr></thead><tbody>
                @foreach ($events as $e)<tr><td class="mono small">{{ $e->created_at->format('d.m.Y H:i:s') }}</td><td class="mono small">{{ $e->trigger }}</td><td class="small">{{ $e->entity_type }} #{{ $e->entity_id }}</td><td class="small">{{ implode(' → ', $e->steps) }}</td><td class="mono">v{{ $e->version_after }}</td></tr>@endforeach
            </tbody></table>
        @endif
    </div>
@endsection
