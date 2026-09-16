@extends('layouts.panel')

@section('title', 'Websiteler')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Faz 10 · Çoklu website</p>
            <h1 class="h2">Websiteler</h1>
        </div>
        <div class="panel-head__actions">
            @can('website.manage')
                <a href="{{ route('panel.websites.create') }}" class="btn btn--brand">Yeni site</a>
            @endcan
        </div>
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Site</th><th>Alan adı</th><th>Organizasyon</th><th></th></tr></thead>
            <tbody>
                @foreach ($websites as $site)
                    <tr>
                        <td>
                            {{ $site->name }}
                            @if ($site->is_default)<span class="badge badge--ok" style="margin-left:8px">Varsayılan</span>@endif
                            <span class="small muted mono" style="display:block;font-weight:400">{{ $site->slug }}</span>
                        </td>
                        <td class="mono small">{{ $site->domain ?: '—' }}</td>
                        <td>{{ $site->organization?->name ?? ($site->is_default ? config('ofisvio.brand.name') : '—') }}</td>
                        <td>
                            <div class="row-actions">
                                <a href="{{ route('panel.content.index', ['website' => $site->id]) }}" class="btn btn--ghost btn--pill">İçerik</a>
                                @can('website.manage')
                                    <a href="{{ route('panel.websites.edit', $site) }}" class="btn btn--ghost btn--pill">Düzenle</a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
