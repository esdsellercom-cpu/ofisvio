@extends('layouts.panel')

@section('title', 'Üyeler — '.$company->legal_name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.companies.show', $company) }}">{{ $company->legal_name }}</a></p>
            <h1 class="h2">Üyeler</h1>
        </div>
    </div>

    <div class="grid-auto" style="--min:320px;--gap:20px;align-items:start">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Kişi</th><th>Rol</th><th>Durum</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($members as $member)
                        <tr>
                            <td>
                                {{ $member->user->name }}
                                <span class="small muted" style="display:block;font-weight:400">{{ $member->user->email }}</span>
                            </td>
                            <td>{{ __('roles.'.$member->role->name) }}</td>
                            <td>
                                @if ($member->isActive())
                                    <span class="badge badge--ok">Aktif</span>
                                @else
                                    <span class="badge badge--muted">Askıda</span>
                                @endif
                            </td>
                            <td>
                                <div class="row-actions">
                                    @if ($member->isActive())
                                        @if ($member->user_id !== auth()->id())
                                            <form method="POST" action="{{ route('panel.companies.members.suspend', [$company, $member]) }}">
                                                @csrf
                                                <button type="submit" class="btn btn--ghost btn--pill">Askıya al</button>
                                            </form>
                                        @else
                                            <span class="small muted">Siz</span>
                                        @endif
                                    @else
                                        <form method="POST" action="{{ route('panel.companies.members.reactivate', [$company, $member]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn--ghost btn--pill">Etkinleştir</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">Henüz üye yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="panel">
            <p class="eyebrow">Üye davet et</p>
            <p class="body-muted" style="margin:0 0 16px">
                Kişi zaten kayıtlıysa yalnızca rol atanır; değilse hesap açılır ve e-postasına şifre belirleme bağlantısı gider.
            </p>
            <form method="POST" action="{{ route('panel.companies.members.store', $company) }}" class="stack" style="gap:14px">
                @csrf
                <label class="field">
                    <span class="label">Ad Soyad</span>
                    <input class="control" type="text" name="name" value="{{ old('name') }}" required minlength="2" maxlength="120" @error('name') aria-invalid="true" @enderror>
                </label>
                <label class="field">
                    <span class="label">E-posta</span>
                    <input class="control" type="email" name="email" value="{{ old('email') }}" required maxlength="190" inputmode="email" @error('email') aria-invalid="true" @enderror>
                </label>
                <label class="field">
                    <span class="label">Rol</span>
                    <select class="control" name="role" required @error('role') aria-invalid="true" @enderror>
                        @foreach ($roles as $role)
                            <option value="{{ $role }}" @selected(old('role', 'employee') === $role)>{{ __('roles.'.$role) }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="btn btn--brand">Davet gönder</button>
            </form>
        </div>
    </div>
@endsection
