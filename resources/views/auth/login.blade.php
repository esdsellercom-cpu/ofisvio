@extends('layouts.auth')

@section('title', 'Giriş')

@section('content')
    <p class="eyebrow">Panel</p>
    <h1 class="h3" style="font-size:26px">Hesabınıza giriş yapın</h1>
    <p class="body-muted" style="margin:10px 0 0">
        Şirketinizin adres, belge ve sözleşme işlemleri tek panelde.
    </p>

    @if (session('status'))
        <div class="notice" role="status" style="margin-top:22px">
            <span class="notice__dot" aria-hidden="true"></span>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    @if ($errors->any())
        <div class="notice notice--error" role="alert" style="margin-top:22px">
            <span class="notice__dot" aria-hidden="true"></span>
            <div>
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="stack" style="gap:16px;margin-top:26px">
        @csrf

        <label class="field">
            <span class="label">E-posta</span>
            <input class="control" type="email" name="email" value="{{ old('email') }}"
                   required autofocus autocomplete="username" inputmode="email"
                   @error('email') aria-invalid="true" @enderror>
        </label>

        <label class="field">
            <span class="label">Şifre</span>
            <input class="control" type="password" name="password" required autocomplete="current-password"
                   @error('password') aria-invalid="true" @enderror>
        </label>

        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
            <label class="checkbox-row" style="margin:0">
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                <span>Beni hatırla</span>
            </label>
            <a href="{{ route('password.request') }}" style="font-size:14px;color:var(--brand);font-weight:500">Şifremi unuttum</a>
        </div>

        <button type="submit" class="btn btn--brand btn--block" style="margin-top:6px">Giriş yap</button>
    </form>
@endsection

@section('foot')
    Hesabınız yok mu? Hesaplar davetle açılır — <a href="{{ route('site.home') }}#teklif" style="color:var(--brand);font-weight:500">teklif alın</a>, sizi biz ekleyelim.
@endsection
