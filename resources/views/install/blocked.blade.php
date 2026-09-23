@extends('install.layout')

@section('title', 'Kurulum başlatılamıyor')

@section('content')
    <p class="setup-bad">{{ $reason }}</p>
    <p class="setup-note">Uygulama kökündeki <span class="mono">.env</span> dosyasının yazılabilir olması gerekir
        (izin 644 ya da 640, sahibi web sunucusu kullanıcısı). Düzelttikten sonra sayfayı yenileyin.</p>
@endsection
