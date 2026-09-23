@extends('install.layout')

@section('title', 'Kurulum')

@section('steps')
    @include('install.steps', ['current' => 'setup'])
@endsection

@section('content')
    <p>İki adım sırayla çalışır. Her biri birkaç dakika sürebilir; sayfa yanıt verene kadar bekleyin ve tarayıcıyı kapatmayın.</p>

    <div class="setup-row">
        <span>1. Veritabanı tabloları</span>
        <span class="{{ $migrated ? 'setup-ok' : 'setup-warn' }}">{{ $migrated ? 'tamam' : 'bekliyor' }}</span>
    </div>
    <form method="POST" action="{{ route('install.setup.migrate') }}" class="setup-actions">
        @csrf
        <button type="submit" class="btn {{ $migrated ? 'btn--ghost' : 'btn--brand' }} btn--pill" data-no-busy>{{ $migrated ? 'Yeniden çalıştır' : 'Tabloları oluştur' }}</button>
    </form>

    <div class="setup-row" style="margin-top:18px">
        <span>2. Referans veri (roller, izinler, hizmet kataloğu, varsayılan site)</span>
        <span class="{{ $seeded ? 'setup-ok' : 'setup-warn' }}">{{ $seeded ? 'tamam' : 'bekliyor' }}</span>
    </div>
    <form method="POST" action="{{ route('install.setup.seed') }}" class="setup-actions">
        @csrf
        <button type="submit" class="btn {{ $seeded ? 'btn--ghost' : 'btn--brand' }} btn--pill" @disabled(! $migrated) data-no-busy>{{ $seeded ? 'Yeniden çalıştır' : 'Referans veriyi yükle' }}</button>
    </form>

    @if ($migrated && $seeded)
        <div class="setup-actions" style="margin-top:22px">
            <a href="{{ route('install.admin') }}" class="btn btn--brand btn--pill">Yönetici hesabına geç</a>
        </div>
    @endif
@endsection
