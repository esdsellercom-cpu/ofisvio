@extends('layouts.auth')

@section('title', 'Şifremi unuttum')

@section('content')
    <p class="eyebrow">Şifre sıfırlama</p>
    <h1 class="h3" style="font-size:26px">Bağlantı gönderelim</h1>
    <p class="body-muted" style="margin:10px 0 0">
        E-posta adresinizi yazın; şifrenizi yeniden belirleyebileceğiniz bir bağlantı gönderelim.
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

    <form method="POST" action="{{ route('password.email') }}" class="stack" style="gap:16px;margin-top:26px">
        @csrf

        <label class="field">
            <span class="label">E-posta</span>
            <input class="control" type="email" name="email" value="{{ old('email') }}"
                   required autofocus autocomplete="username" inputmode="email"
                   @error('email') aria-invalid="true" @enderror>
        </label>

        <button type="submit" class="btn btn--brand btn--block">Bağlantı gönder</button>
    </form>
@endsection

@section('foot')
    <a href="{{ route('login') }}" style="color:var(--brand);font-weight:500">Girişe dön</a>
@endsection
