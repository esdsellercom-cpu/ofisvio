@extends('layouts.auth')

@section('title', 'Şifre onayı')

@section('content')
    <p class="eyebrow">Güvenlik</p>
    <h1 class="h3" style="font-size:26px">Devam etmek için giriş şifrenizi doğrulayın</h1>
    <p class="body-muted" style="margin:10px 0 0">
        Bu işlem hesabınızın güvenlik ayarlarını değiştirir; kısa süre önce şifrenizi doğrulamış olmanız gerekir.
        Buraya <strong>hesap giriş şifrenizi</strong> yazın — doğrulayıcı uygulamanın 6 haneli kodu değil; kod bir sonraki adımda istenir.
    </p>

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

    <form method="POST" action="{{ route('password.confirm.store') }}" class="stack" style="gap:16px;margin-top:26px">
        @csrf
        <label class="field">
            <span class="label">Giriş şifresi</span>
            <input class="control" type="password" name="password" required autofocus autocomplete="current-password" @error('password') aria-invalid="true" @enderror>
        </label>
        <button type="submit" class="btn btn--brand btn--block">Doğrula</button>
    </form>
@endsection

@section('foot')
    <a href="{{ route('panel.account') }}" style="color:var(--brand);font-weight:500">Hesaba dön</a>
@endsection
