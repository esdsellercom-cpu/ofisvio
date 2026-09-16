@extends('layouts.panel')

@section('title', 'Doctor kontrolü')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.performance.index') }}">Performans</a></p>
            <h1 class="h2">Doctor kontrolü</h1>
        </div>
        <div class="panel-head__actions">
            @if ($report['ok'])<span class="badge badge--ok">Hata yok</span>@else<span class="badge badge--danger">Hata var</span>@endif
            <span class="badge badge--muted mono">ortam: {{ $report['env'] ?? config('app.env') }}</span>
        </div>
    </div>

    <p class="body-muted" style="margin:0 0 18px;max-width:72ch">
        <code>php artisan ofisvio:doctor</code> ile aynı kontroller (canlı clamd taraması, önbellek yazma, migrasyon deposu, zamanlayıcı).
        Üretimde tek hata bile deploy kapısını kapatır.
    </p>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th></th><th>Kontrol</th><th>Not</th></tr></thead>
            <tbody>
                @foreach ($report['rows'] as $r)
                    <tr>
                        <td>@if ($r['level'] === 'ok')<span class="badge badge--ok">✓</span>@elseif ($r['level'] === 'warn')<span class="badge badge--warn">!</span>@else<span class="badge badge--danger">✗</span>@endif</td>
                        <td>{{ $r['name'] }}</td>
                        <td class="small">{{ $r['note'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
