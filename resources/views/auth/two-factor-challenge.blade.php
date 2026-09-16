@extends('layouts.auth')

@section('title', 'İki adımlı doğrulama')

@section('content')
    <p class="eyebrow">Güvenlik</p>
    <h1 class="h3" style="font-size:26px">İki adımlı doğrulama</h1>
    <p class="body-muted" style="margin:10px 0 0">
        Kimlik doğrulayıcı uygulamanızdaki 6 haneli kodu girin. Uygulamaya erişemiyorsanız kurtarma kodlarınızdan birini kullanın.
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

    <form method="POST" action="{{ route('two-factor.login.store') }}" class="stack" style="gap:16px;margin-top:26px">
        @csrf

        <label class="field">
            <span class="label">Doğrulama kodu</span>
            <input class="control mono" type="text" name="code" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" autofocus
                   style="letter-spacing:.3em;font-size:20px" @error('code') aria-invalid="true" @enderror>
        </label>

        <details>
            <summary class="small" style="cursor:pointer;color:var(--brand);font-weight:500">Kurtarma kodu kullan</summary>
            <label class="field" style="margin-top:12px">
                <span class="label">Kurtarma kodu</span>
                <input class="control mono" type="text" name="recovery_code" autocomplete="off" @error('recovery_code') aria-invalid="true" @enderror>
            </label>
        </details>

        <button type="submit" class="btn btn--brand btn--block">Doğrula</button>
    </form>
@endsection

@section('foot')
    <a href="{{ route('login') }}" style="color:var(--brand);font-weight:500">Girişe dön</a>
@endsection
