@extends('layouts.panel')

@section('title', 'Personel davet et')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.users.index') }}">Kullanıcılar</a></p>
            <h1 class="h2">Personel davet et</h1>
        </div>
    </div>

    <div class="panel" style="max-width:560px">
        <form method="POST" action="{{ route('panel.users.store') }}" class="stack" style="gap:14px">
            @csrf
            <label class="field"><span class="label">Ad Soyad</span>
                <input class="control" type="text" name="name" value="{{ old('name') }}" required minlength="2" maxlength="120" @error('name') aria-invalid="true" @enderror>
            </label>
            <label class="field"><span class="label">E-posta</span>
                <input class="control" type="email" name="email" value="{{ old('email') }}" required maxlength="190" @error('email') aria-invalid="true" @enderror>
                @error('email')<span class="small" style="color:var(--danger)">{{ $message }}</span>@enderror
            </label>
            <label class="field"><span class="label">Rol (global, personel)</span>
                <select class="control" name="role" required @error('role') aria-invalid="true" @enderror>
                    @foreach ($roles as $role)
                        <option value="{{ $role->name }}" @selected(old('role') === $role->name)>{{ __('roles.'.$role->name) }} ({{ $role->name }})</option>
                    @endforeach
                </select>
                @error('role')<span class="small" style="color:var(--danger)">{{ $message }}</span>@enderror
            </label>
            <p class="small muted" style="margin:0">Kişiye şifre belirleme bağlantısı e-postayla gider; ilk girişte 2FA kurması zorunludur.</p>
            <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn--brand">Davet gönder</button>
                <a href="{{ route('panel.users.index') }}" class="btn btn--ghost">Vazgeç</a>
            </div>
        </form>
    </div>
@endsection
