@extends('layouts.panel')

@section('title', 'SEO denetimi — '.$website->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.index') }}">SEO</a> · {{ $website->name }}</p>
            <h1 class="h2">İçerik denetimi</h1>
        </div>
        <div class="panel-head__actions">
            @if ($findings === [])
                <span class="badge badge--ok">Sorun yok</span>
            @else
                <span class="badge badge--warn">{{ count($findings) }} içerikte bulgu</span>
            @endif
        </div>
    </div>

    @if ($findings !== [])
        <div class="table-wrap" style="margin-bottom:24px">
            <table class="data">
                <thead><tr><th>İçerik</th><th>Bulgular</th><th></th></tr></thead>
                <tbody>
                    @foreach ($findings as $f)
                        <tr>
                            <td>{{ $f['content']->title }}<span class="small muted mono" style="display:block;font-weight:400">{{ $f['content']->path() }}</span></td>
                            <td><ul style="margin:0;padding-left:18px;font-size:14px">@foreach ($f['issues'] as $issue)<li>{{ $issue }}</li>@endforeach</ul></td>
                            <td><div class="row-actions"><a href="{{ route('panel.content.show', $f['content']) }}" class="btn btn--ghost btn--pill">Aç</a></div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="panel">
        <p class="eyebrow">Sitemap ({{ count($sitemap) }} URL)</p>
        @if ($sitemap === [])
            <p class="body-muted" style="margin:0">Sitemap boş — site indekslenmiyor ya da yayında içerik yok.</p>
        @else
            <ul class="mono small" style="margin:0;padding-left:18px;columns:2;column-gap:32px">
                @foreach ($sitemap as $entry)<li>{{ $entry['loc'] }}</li>@endforeach
            </ul>
        @endif
    </div>
@endsection
