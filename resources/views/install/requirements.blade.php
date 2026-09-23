@extends('install.layout')

@section('title', 'Sunucu gereksinimleri')

@section('steps')
    @include('install.steps', ['current' => 'requirements'])
@endsection

@section('content')
    @foreach ($rows as $row)
        <div class="setup-row">
            <span>{{ $row['name'] }}</span>
            <span class="{{ $row['ok'] ? 'setup-ok' : ($row['blocking'] ? 'setup-bad' : 'setup-warn') }}">
                {{ $row['ok'] ? 'uygun' : ($row['blocking'] ? 'eksik' : 'uyarı') }}
                @if ($row['note'] !== '')<span class="setup-note">— {{ $row['note'] }}</span>@endif
            </span>
        </div>
    @endforeach

    <div class="setup-actions">
        @if ($ready)
            <a href="{{ route('install.database') }}" class="btn btn--brand btn--pill">Devam</a>
        @else
            <a href="{{ route('install.requirements') }}" class="btn btn--ghost btn--pill">Yeniden denetle</a>
            <span class="setup-note">Kırmızı satırlar düzeltilmeden kuruluma devam edilemez.</span>
        @endif
    </div>
@endsection
