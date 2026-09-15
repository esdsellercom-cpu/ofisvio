@extends('layouts.auth')

@section('title', 'Yeni şifre belirle')

@section('content')
    <p class="eyebrow">Şifre sıfırlama</p>
    <h1 class="h3" style="font-size:26px">Yeni şifrenizi belirleyin</h1>
    <p class="body-muted" style="margin:10px 0 0">
        En az 8 karakter. Davetle geldiyseniz bu adım hesabınızı da etkinleştirir.
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

    <form method="POST" action="{{ route('password.update') }}" class="stack" style="gap:16px;margin-top:26px">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <label class="field">
            <span class="label">E-posta</span>
            <input class="control" type="email" name="email" value="{{ old('email', $request->email) }}"
                   required autocomplete="username" inputmode="email"
                   @error('email') aria-invalid="true" @enderror>
        </label>

        <label class="field">
            <span class="label">Yeni şifre</span>
            <input class="control" type="password" name="password" required autocomplete="new-password"
                   @error('password') aria-invalid="true" @enderror>
        </label>

        <label class="field">
            <span class="label">Yeni şifre (tekrar)</span>
            <input class="control" type="password" name="password_confirmation" required autocomplete="new-password">
        </label>

        <button type="submit" class="btn btn--brand btn--block">Şifreyi kaydet</button>
    </form>
@endsection

@section('foot')
    <a href="{{ route('login') }}" style="color:var(--brand);font-weight:500">Girişe dön</a>
@endsection
