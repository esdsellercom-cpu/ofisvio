@extends('layouts.panel')

@section('title', 'Önbellek anahtarları — '.$website->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.cache.index') }}">Önbellek</a> · {{ $website->name }}</p>
            <h1 class="h2">Anahtarlar</h1>
        </div>
        <div class="panel-head__actions">
            <span class="badge badge--muted mono">v{{ $stats['version'] }} · TTL {{ $ttl }} sn</span>
        </div>
    </div>

    <p class="body-muted" style="margin:0 0 18px;max-width:72ch">
        Bu site için üretilmiş anahtar adları (sürücü listeleme yapmaz; adlar <code>remember()</code> anında kaydedilir).
        İçerik gösterilmez — yalnız tür, öğe sayısı, boyut ve ilk üç başlık. "Yok" = süresi dolmuş ya da bu sürümde henüz üretilmemiş.
    </p>

    @if ($rows === [])
        <div class="empty-state">Henüz kayıtlı anahtar yok — vitrin ziyaret edilince ya da "Isıt" ile dolar.</div>
    @else
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Ad</th><th>Durum</th><th>Tür</th><th class="num">Öğe</th><th class="num">Boyut</th><th>Önizleme</th></tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td class="mono small">{{ $r['name'] }}<span class="muted" style="display:block;font-size:11px">{{ $r['key'] }}</span></td>
                            <td>@if ($r['present'])<span class="badge badge--ok">Var</span>@else<span class="badge badge--muted">Yok</span>@endif</td>
                            <td class="mono small">{{ $r['type'] }}</td>
                            <td class="num mono">{{ $r['count'] ?? '—' }}</td>
                            <td class="num mono">{{ $r['bytes'] === null ? '—' : number_format($r['bytes'] / 1024, 1).' KB' }}</td>
                            <td class="small muted">{{ implode(' · ', $r['preview']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection