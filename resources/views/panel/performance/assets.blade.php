@extends('layouts.panel')

@section('title', 'Asset optimizasyonu')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Performance Command Center</p>
            <h1 class="h2">Asset optimizasyonu</h1>
            <p>public/css, public/js ve Vite build çıktısı: ham ve gzip boyutu. Tüm asset'ler <span class="mono">asset_v()</span> ile sürümlenir (uzun max-age güvenli). Görseller yüklemede otomatik küçültülür (2400 px) ve 480/960/1600 varyantlarıyla srcset olarak basılır; tembel yükleme Teknik SEO sekmesinden.</p>
        </div>
    </div>
    @include('panel.performance.partials.nav')
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Dosya</th><th>Boyut</th><th>gzip</th><th>Not</th></tr></thead>
        <tbody>
            @foreach ($assets as $a)<tr><td class="mono small">{{ $a['file'] }}</td><td class="mono">{{ number_format($a['bytes'] / 1024, 1, ',', '.') }} KB</td><td class="mono">{{ $a['gzip_bytes'] !== null ? number_format($a['gzip_bytes'] / 1024, 1, ',', '.').' KB' : '—' }}</td><td class="small">{{ $a['note'] ?? '' }}</td></tr>@endforeach
        </tbody>
    </table></div>
@endsection
