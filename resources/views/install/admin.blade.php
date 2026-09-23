@extends('install.layout')

@section('title', 'Yönetici hesabı')

@section('steps')
    @include('install.steps', ['current' => 'admin'])
@endsection

@section('content')
    @if (! $ready)
        <p class="setup-bad">Önce veritabanı tabloları ve referans veri adımlarını tamamlayın.</p>
        <div class="setup-actions"><a href="{{ route('install.setup') }}" class="btn btn--ghost btn--pill">Kurulum adımına dön</a></div>
    @elseif (! $secure)
        <p class="setup-bad">Bu sayfa HTTPS üzerinden açılmalı: şifre şifresiz bağlantıda alınmaz.</p>
        <p class="setup-note">SSL sertifikanızı etkinleştirip <span class="mono">https://</span> adresiyle yeniden girin.
            Cloudflare ya da nginx arkasındaysanız bir önceki adımdaki "Ters proxy" alanını doldurun.</p>
    @else
        <p>Bu hesap sistemin ilk süper yöneticisidir. İlk girişte iki adımlı doğrulama (2FA) kurulumu zorunludur.</p>
        <form method="POST" action="{{ route('install.admin.store') }}">
            @csrf
            <label class="setup-field"><span>Ad soyad</span><input type="text" name="name" value="{{ old('name') }}" required maxlength="120"></label>
            <label class="setup-field"><span>E-posta</span><input type="email" name="email" value="{{ old('email') }}" required maxlength="190"></label>
            <label class="setup-field"><span>Şifre</span><input type="password" name="password" autocomplete="new-password" required maxlength="190"></label>
            <label class="setup-field"><span>Şifre (tekrar)</span><input type="password" name="password_confirmation" autocomplete="new-password" required maxlength="190"></label>
            <p class="setup-note">Şifre politikası: {{ $policy }}.</p>
            <div class="setup-actions">
                <button type="submit" class="btn btn--brand btn--pill">Hesabı oluştur</button>
            </div>
        </form>
    @endif
@endsection
