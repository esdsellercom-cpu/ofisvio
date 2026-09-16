@extends('layouts.panel')

@section('title', 'Hesap')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Hesap</p>
            <h1 class="h2">{{ $user->name }}</h1>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.account.security') }}" class="btn btn--ghost">
                Güvenlik · 2FA
                @if ($user->hasConfirmedTwoFactor())
                    <span class="badge badge--ok">Açık</span>
                @else
                    <span class="badge badge--warn">Kapalı</span>
                @endif
            </a>
        </div>
    </div>

    @if ($isStaffUser && ! $user->hasConfirmedTwoFactor())
        <div class="notice notice--error" role="alert" style="margin-bottom:22px">
            <span class="notice__dot" aria-hidden="true"></span>
            <div>
                <strong>Personel hesabınızda iki adımlı doğrulama kapalı.</strong>
                Müşteri belgelerine erişen hesaplarda 2FA zorunludur; <a href="{{ route('panel.account.security') }}" style="color:var(--brand);font-weight:600">şimdi etkinleştirin</a>.
            </div>
        </div>
    @endif

    <div class="grid-auto" style="--min:320px;--gap:20px;align-items:start">
        <div class="panel">
            <p class="eyebrow">Profil</p>
            <form method="POST" action="{{ route('user-profile-information.update') }}" class="stack" style="gap:14px">
                @csrf
                @method('PUT')
                <label class="field">
                    <span class="label">Ad Soyad</span>
                    <input class="control" type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="255"
                           @error('name', 'updateProfileInformation') aria-invalid="true" @enderror>
                </label>
                <label class="field">
                    <span class="label">E-posta</span>
                    <input class="control" type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255"
                           @error('email', 'updateProfileInformation') aria-invalid="true" @enderror>
                </label>
                @if ($errors->updateProfileInformation->any())
                    <div class="field-error">{{ $errors->updateProfileInformation->first() }}</div>
                @endif
                <button type="submit" class="btn btn--brand">Kaydet</button>
            </form>
        </div>

        <div class="panel">
            <p class="eyebrow">Şifre</p>
            <form method="POST" action="{{ route('user-password.update') }}" class="stack" style="gap:14px">
                @csrf
                @method('PUT')
                <label class="field">
                    <span class="label">Mevcut şifre</span>
                    <input class="control" type="password" name="current_password" required autocomplete="current-password"
                           @error('current_password', 'updatePassword') aria-invalid="true" @enderror>
                </label>
                <label class="field">
                    <span class="label">Yeni şifre</span>
                    <input class="control" type="password" name="password" required autocomplete="new-password"
                           @error('password', 'updatePassword') aria-invalid="true" @enderror>
                </label>
                <label class="field">
                    <span class="label">Yeni şifre (tekrar)</span>
                    <input class="control" type="password" name="password_confirmation" required autocomplete="new-password">
                </label>
                @if ($errors->updatePassword->any())
                    <div class="field-error">{{ $errors->updatePassword->first() }}</div>
                @endif
                <button type="submit" class="btn btn--brand">Şifreyi değiştir</button>
            </form>
        </div>
    </div>
@endsection
