@extends('layouts.panel')

@section('title', 'Yeni şube')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.geo.index') }}">GEO &amp; lokasyonlar</a></p>
            <h1 class="h2">Yeni şube</h1>
        </div>
    </div>

    <div class="panel" style="max-width:720px">
        <p class="small muted" style="margin:0 0 14px">Şube <strong>vitrinde değil</strong> açılır; koordinat/telefon/saatleri girip "Vitrine al" ile yayınlarsınız.</p>
        <form method="POST" action="{{ route('panel.geo.store') }}" class="stack" style="gap:12px">
            @csrf
            @include('panel.geo.partials.basics-fields', ['loc' => null])
            <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn--brand">Şubeyi aç</button>
                <a href="{{ route('panel.geo.index') }}" class="btn btn--ghost">Vazgeç</a>
            </div>
        </form>
    </div>
@endsection