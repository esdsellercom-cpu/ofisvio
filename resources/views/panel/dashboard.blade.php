@extends('layouts.panel')

@section('title', 'Genel bakış')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">{{ $activeOrganization->name }}</p>
            <h1 class="h2">Genel bakış</h1>
            <p>{{ $isStaff ? 'Aktif organizasyonun şirketleri ve belge süreci; operasyon özeti ayrı panelde.' : 'Şirketleriniz, belge süreci ve hızlı işlemler.' }}</p>
        </div>
        <div class="panel-head__actions qa">
            @can('organization.manage')
                <a href="{{ route('panel.companies.create') }}" class="btn btn--brand">Yeni şirket</a>
            @endcan
            @if ($isStaff)
                <a href="{{ route('panel.operations') }}">Operasyon paneli</a>
            @else
                @foreach ($rows->take(1) as $row)
                    @can('booking.view', $row['company'])<a href="{{ route('panel.companies.bookings.index', $row['company']) }}">Rezervasyonlarım</a>@endcan
                @endforeach
                <a href="{{ route('panel.account') }}">Hesabım</a>
            @endif
        </div>
    </div>

    <div class="stack" style="gap:18px">
        <div class="kpis" style="grid-template-columns:repeat(3,minmax(0,1fr))">
            <div class="kpi"><span class="k">Şirket</span><span class="v">{{ $counts['total'] }}</span><span class="d">Bu organizasyonda görebildikleriniz</span></div>
            <div class="kpi {{ $counts['in_kyc'] > 0 ? 'watch' : '' }}"><span class="k">Belge sürecinde</span><span class="v">{{ $counts['in_kyc'] }}</span><span class="d">KYC bekleyen şirket</span></div>
            <div class="kpi ok"><span class="k">Aktif</span><span class="v">{{ $counts['active'] }}</span><span class="d">Sözleşmesi yürüyen</span></div>
        </div>

        @if ($isStaff)
            <div class="note">Rezervasyon, tahsilat, üyelik, etkinlik ve franchise toplamları organizasyondan bağımsızdır: <a href="{{ route('panel.operations') }}"><b>Operasyon paneli</b></a>.</div>
        @endif

        {{-- Şirketler (aktif organizasyon) --}}
        <div class="card">
            <div class="card__head"><h3>Şirketler</h3><span class="sub">{{ $activeOrganization->name }} · {{ $counts['total'] }} şirket</span><span class="r"><a href="{{ route('panel.companies.index') }}" class="btn btn--quiet">Tümü</a></span></div>
            @if ($rows->isEmpty())
                <div class="empty-state" style="border:0">
                    Bu organizasyonda görebildiğiniz şirket yok.
                    @can('organization.manage')
                        <a href="{{ route('panel.companies.create') }}" style="color:var(--accent-2);font-weight:600">İlk şirketi açın.</a>
                    @endcan
                </div>
            @else
                <div class="tw">
                    <table class="t">
                        <thead>
                            <tr>
                                <th>Şirket</th>
                                <th>Durum</th>
                                <th>KYC belgeleri</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td><b><a href="{{ route('panel.companies.show', $row['company']) }}">{{ $row['company']->legal_name }}</a></b></td>
                                    <td>@include('panel.partials.company-status', ['status' => $row['company']->status])</td>
                                    <td>
                                        @if ($row['kyc']['complete'])
                                            <span class="pill g">Tamam</span>
                                        @else
                                            <span class="pill w">{{ count($row['kyc']['missing']) }} zorunlu belge eksik</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            @canany(['kyc.view', 'kyc.view_status'], $row['company'])
                                                <a href="{{ route('panel.companies.kyc.show', $row['company']) }}" class="btn btn--ghost btn--pill">Belgeler</a>
                                            @endcanany
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @can('organization.manage')
            <div class="card" style="max-width:520px">
                <div class="card__head"><h3>Organizasyon künyesi</h3></div>
                <div class="card__body">
                    <form method="POST" action="{{ route('panel.organization.update') }}" class="stack" style="gap:10px">
                        @csrf @method('PUT')
                        <label class="field"><span class="label">Organizasyon adı</span>
                            <input class="control" type="text" name="name" value="{{ old('name', $activeOrganization->name) }}" required minlength="2" maxlength="120" @error('name') aria-invalid="true" @enderror>
                        </label>
                        <div><button type="submit" class="btn btn--ghost">Kaydet</button></div>
                    </form>
                </div>
            </div>
        @endcan
    </div>
@endsection
