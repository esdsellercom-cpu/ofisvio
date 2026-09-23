@extends('install.layout')

@section('title', 'Kurulumu bitir')

@section('steps')
    @include('install.steps', ['current' => 'finish'])
@endsection

@section('content')
    @if (! $ready)
        <p class="setup-bad">Yönetici hesabı henüz oluşturulmadı.</p>
        <div class="setup-actions"><a href="{{ route('install.admin') }}" class="btn btn--ghost btn--pill">Yönetici adımına dön</a></div>
    @else
        <p>Son adım kurulumu kapatır: kilit dosyası yazılır, kurulum anahtarı silinir ve bu adres kalıcı olarak
            erişilemez olur. Ardından sistem denetiminin özetini göreceksiniz.</p>
        <form method="POST" action="{{ route('install.complete') }}">
            @csrf
            <div class="setup-actions">
                <button type="submit" class="btn btn--brand btn--pill" data-no-busy>Kurulumu bitir ve kapat</button>
            </div>
        </form>
    @endif
@endsection
