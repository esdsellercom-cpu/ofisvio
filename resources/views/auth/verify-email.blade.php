@extends('layouts.auth')

@section('title', 'E-posta doğrulama')

@section('content')
    <p class="eyebrow">Hesap</p>
    <h1 class="h3" style="font-size:26px">E-posta adresinizi doğrulayın</h1>
    <p class="body-muted" style="margin:10px 0 0">
        Panele girmeden önce <strong>{{ auth()->user()->email }}</strong> adresine gönderdiğimiz bağlantıya tıklamanız gerekir.
        E-posta gelmediyse yeniden gönderebilirsiniz.
    </p>

    @if (session('status') === 'verification-link-sent')
        <div class="notice" role="status" style="margin-top:22px"><span class="notice__dot" aria-hidden="true"></span><div>Doğrulama bağlantısı yeniden gönderildi.</div></div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}" class="stack" style="gap:12px;margin-top:26px">
        @csrf
        <button type="submit" class="btn btn--brand btn--block">Doğrulama e-postasını yeniden gönder</button>
    </form>
    <form method="POST" action="{{ route('logout') }}" style="margin-top:12px">
        @csrf
        <button type="submit" class="btn btn--ghost btn--block">Çıkış yap</button>
    </form>
@endsection
