@extends('layouts.panel')

@section('title', 'Kullanıcılar')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Yönetim</p>
            <h1 class="h2">Kullanıcılar</h1>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.onboarding.create') }}" class="btn btn--ghost">Yeni müşteri</a>
            <a href="{{ route('panel.users.create') }}" class="btn btn--brand">Personel davet et</a>
        </div>
    </div>

    <p class="body-muted" style="margin:0 0 18px;max-width:72ch">
        Personel (global rol) burada davet edilir ve rolleri yönetilir. Müşteri kullanıcıları organizasyon açılışı ve şirket üyelikleriyle gelir;
        burada yalnızca görünürler. Şifre hiçbir zaman burada belirlenmez — davet, şifre belirleme bağlantısıdır.
    </p>

    <form method="GET" class="inline-form" style="margin-bottom:18px">
        <label class="field" style="flex:1 1 260px"><span class="label">Ara (ad, e-posta)</span>
            <input class="control" type="search" name="q" value="{{ $q }}" maxlength="120">
        </label>
        <button type="submit" class="btn btn--ghost">Ara</button>
    </form>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Ad</th><th>E-posta</th><th>Roller</th><th>2FA</th><th></th></tr></thead>
            <tbody>
                @foreach ($users as $u)
                    @php($global = $u->userRoles->filter(fn ($r) => $r->company_id === null && $r->organization_id === null && $r->location_id === null))
                    @php($scoped = $u->userRoles->count() - $global->count())
                    <tr>
                        <td><a href="{{ route('panel.users.show', $u) }}">{{ $u->name }}</a></td>
                        <td class="mono small">{{ $u->email }}@if ($u->isSuspended()) <span class="pill c flat">askıda</span>@endif</td>
                        <td class="small">
                            @foreach ($global as $r)
                                <span class="badge badge--{{ $r->status === 'active' ? 'info' : 'muted' }}" title="{{ $r->status }}">{{ __('roles.'.$r->role->name) }}</span>
                            @endforeach
                            @if ($scoped > 0)<span class="muted">müşteri rolü ×{{ $scoped }}</span>@endif
                            @if ($u->organizationMemberships->isNotEmpty())<span class="muted"> · {{ $u->organizationMemberships->map(fn ($m) => $m->organization?->name)->filter()->implode(', ') }}</span>@endif
                        </td>
                        <td>@if ($u->hasConfirmedTwoFactor())<span class="badge badge--ok">Açık</span>@else<span class="badge badge--warn">Yok</span>@endif</td>
                        <td><div class="row-actions"><a href="{{ route('panel.users.show', $u) }}" class="btn btn--ghost btn--pill">Aç</a></div></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div style="margin-top:16px">{{ $users->links() }}</div>
@endsection
