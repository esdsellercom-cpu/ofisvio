@extends('layouts.panel')

@section('title', 'Üyeler & müşteriler')

{{-- 360° üye merkezi (faz 51): dizin korunur; firma, üyelik/sözleşme durumu, bakiye/borç, son ödeme, sözleşme bitişi sütunları
     ve süzgeçler eklendi. Finans şirket bazlıdır (fatura/tahsilat şirkete kesilir); aynı şirketin üyeleri aynı özeti taşır. --}}
@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">{{ $activeOrganization->name }} · üyelik</p>
            <h1 class="h2">Üyeler &amp; müşteriler</h1>
            <p>Görebildiğiniz şirketlerin üyeleri. Üye adına tıklayın: finans, sözleşme, hizmet, tahsis, demirbaş, belge ve aktivite tek profilde.</p>
        </div>
        <div class="panel-head__actions">
            <form method="GET" class="inline-form">
                <label class="field" style="flex:1 1 240px"><span class="label">Ara</span><input class="control" type="search" name="q" value="{{ $q }}" placeholder="ad, soyad, firma, e-posta, telefon, üye no"></label>
                <label class="field"><span class="label">Süzgeç</span><select class="control" name="f" onchange="this.form.requestSubmit()">@foreach ($filters as $k => $l)<option value="{{ $k }}" @selected($filter === $k)>{{ $l }}</option>@endforeach</select></label>
                <button type="submit" class="btn btn--ghost">Ara</button>
            </form>
            @if ($canCreate)<a href="{{ route('panel.members.create') }}" class="btn btn--brand">+ Yeni üye</a>@endif
        </div>
    </div>

    <div class="stack" style="gap:18px">
        <div class="kpis">
            <div class="kpi"><span class="k">Üyelik</span><span class="v">{{ $counts['members'] }}</span><span class="d">{{ $counts['companies'] }} şirkette</span></div>
            <div class="kpi ok"><span class="k">Aktif</span><span class="v">{{ $counts['active'] }}</span><span class="d">Askıda: {{ $counts['members'] - $counts['active'] }}</span></div>
            <div class="kpi {{ $counts['debtors'] > 0 ? 'warn' : '' }}"><span class="k">Borçlu firma</span><span class="v">{{ $counts['debtors'] }}</span><span class="d">Açık borç {{ money($counts['debt_total']) }}</span></div>
            <div class="kpi"><span class="k">Şirket</span><span class="v">{{ $counts['companies'] }}</span><span class="d"><a href="{{ route('panel.companies.index') }}">Şirketler</a></span></div>
        </div>

        <div class="card">
            <div class="card__head"><h3>Üye dizini</h3><span class="sub">{{ $rows->count() }} kayıt{{ $filter !== '' ? ' · '.$filters[$filter] : '' }}</span></div>
            @if ($rows->isEmpty())
                <div class="empty-state" style="border:0">{{ $q !== '' || $filter !== '' ? 'Eşleşen üye yok.' : 'Henüz üye yok.' }}</div>
            @else
                <div class="tw">
                    <table class="t">
                        <thead><tr><th>Üye</th><th>Firma</th><th>Üyelik</th><th>Sözleşme</th><th class="num">Borç</th><th class="num">Bakiye</th><th>Son ödeme</th><th>Sözleşme bitişi</th><th>Son güncelleme</th><th>Durum</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($rows as $m)
                                @php($f = $finance[$m->company_id] ?? \App\Services\MemberCenterService::emptyFinance())
                                @php($c = $contracts[$m->company_id] ?? null)
                                @php($days = $c?->daysLeft())
                                <tr>
                                    <td><div class="who2">@if ($m->profile?->avatar)<img src="{{ $m->profile->avatar->urlFor(120) }}" alt="" class="ap-av" style="object-fit:cover">@else<span class="ap-av" aria-hidden="true">{{ mb_strtoupper(mb_substr($m->user->name, 0, 1)) }}</span>@endif<div><a href="{{ route('panel.members.show', $m) }}"><b>{{ $m->user->name }}</b></a><small>{{ $m->user->email }}@if ($m->profile?->member_no) · <span class="mono">{{ $m->profile->member_no }}</span>@endif</small></div></div></td>
                                    <td><a href="{{ route('panel.companies.show', $m->company) }}">{{ $m->company->legal_name }}</a><small style="display:block">{{ __('roles.'.$m->role->name) }}</small></td>
                                    <td>{{ $m->profile?->membership_type ? (\App\Models\MemberProfile::MEMBERSHIP_TYPES[$m->profile->membership_type] ?? $m->profile->membership_type) : '—' }}</td>
                                    <td>@if ($c)<span class="pill {{ $days !== null && $days < 0 ? 'c' : ($days !== null && $days <= 30 ? 'w' : 'g') }} flat">{{ $c->statusLabel() }}</span>@else<span class="muted small">yok</span>@endif</td>
                                    <td class="num">@if ($f['remaining'] > 0)<b style="color:var(--danger)">{{ money($f['remaining'], $f['currency']) }}</b>@else<span class="muted">—</span>@endif</td>
                                    <td class="num">{{ $f['balance'] > 0 ? money($f['balance'], $f['currency']) : '—' }}</td>
                                    <td class="small">{{ $f['last_payment']?->format('d.m.Y') ?? '—' }}</td>
                                    <td class="small">@if ($c?->ends_on){{ $c->ends_on->format('d.m.Y') }} @if ($days !== null)<span class="muted">({{ $days < 0 ? 'geçti' : $days.' gün' }})</span>@endif @else — @endif</td>
                                    <td class="small">{{ ($m->profile?->updated_at ?? $m->updated_at)?->format('d.m.Y') }}</td>
                                    <td>@if ($m->isActive())<span class="pill g">Aktif</span>@else<span class="pill n">Pasif</span>@endif</td>
                                    <td class="num"><a href="{{ route('panel.members.show', $m) }}" class="btn btn--quiet">Aç</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
