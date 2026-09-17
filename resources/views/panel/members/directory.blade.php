@extends('layouts.panel')

@section('title', 'Üyeler & kullanıcılar')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">{{ $activeOrganization->name }} · üyelik</p>
            <h1 class="h2">Üyeler &amp; kullanıcılar</h1>
            <p>Görebildiğiniz şirketlerin üyeleri (şirket kapsamlı roller). Davet, askıya alma ve rol işlemleri şirketin üye ekranında.</p>
        </div>
        <div class="panel-head__actions">
            <form method="GET" class="inline-form">
                <label class="field" style="flex:1 1 220px"><span class="label">Ara</span><input class="control" type="search" name="q" value="{{ $q }}" placeholder="ad ya da e-posta"></label>
                <button type="submit" class="btn btn--ghost">Ara</button>
            </form>
        </div>
    </div>

    <div class="stack" style="gap:18px">
        <div class="kpis">
            <div class="kpi"><span class="k">Üyelik</span><span class="v">{{ $counts['members'] }}</span><span class="d">{{ $counts['companies'] }} şirkette</span></div>
            <div class="kpi ok"><span class="k">Aktif</span><span class="v">{{ $counts['active'] }}</span><span class="d">Askıda: {{ $counts['members'] - $counts['active'] }}</span></div>
            <div class="kpi"><span class="k">Kişi</span><span class="v">{{ $counts['people'] }}</span><span class="d">Birden çok şirkette olanlar tek sayılır</span></div>
            <div class="kpi"><span class="k">Şirket</span><span class="v">{{ $counts['companies'] }}</span><span class="d"><a href="{{ route('panel.companies.index') }}">Şirketler</a></span></div>
        </div>

        <div class="card">
            <div class="card__head"><h3>Üye dizini</h3><span class="sub">{{ $members->count() }} kayıt</span></div>
            @if ($members->isEmpty())
                <div class="empty-state" style="border:0">{{ $q !== '' ? 'Eşleşen üye yok.' : 'Henüz üye yok. Şirket sayfasından davet edin.' }}</div>
            @else
                <div class="tw">
                    <table class="t">
                        <thead><tr><th>Kişi</th><th>Şirket</th><th>Rol</th><th>Durum</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($members as $m)
                                <tr>
                                    <td><div class="who2"><span class="ap-av" aria-hidden="true">{{ mb_strtoupper(mb_substr($m->user->name, 0, 1)) }}</span><div><b>{{ $m->user->name }}</b><small>{{ $m->user->email }}</small></div></div></td>
                                    <td><a href="{{ route('panel.companies.show', $m->company) }}">{{ $m->company->legal_name }}</a></td>
                                    <td><span class="tag">{{ __('roles.'.$m->role->name) }}</span></td>
                                    <td>@if ($m->isActive())<span class="pill g">Aktif</span>@else<span class="pill n">Askıda</span>@endif</td>
                                    <td class="num">@can('membership.manage', $m->company)<a href="{{ route('panel.companies.members.index', $m->company) }}" class="btn btn--quiet">Yönet</a>@endcan</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
