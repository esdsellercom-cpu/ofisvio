@extends('layouts.panel')

@section('title', 'Genel bakış')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">{{ $activeOrganization->name }}</p>
            <h1 class="h2">Genel bakış</h1>
        </div>
        <div class="panel-head__actions">
            @can('organization.manage')
                <a href="{{ route('panel.companies.create') }}" class="btn btn--brand">Yeni şirket</a>
            @endcan
        </div>
    </div>

    <div class="grid-auto" style="--min:180px;--gap:14px;margin-bottom:28px">
        <div class="card stat"><span class="stat__value">{{ $counts['total'] }}</span><span class="stat__label">Şirket</span></div>
        <div class="card stat"><span class="stat__value">{{ $counts['in_kyc'] }}</span><span class="stat__label">Belge sürecinde</span></div>
        <div class="card stat"><span class="stat__value">{{ $counts['active'] }}</span><span class="stat__label">Aktif</span></div>
    </div>

    @if ($rows->isEmpty())
        <div class="empty-state">
            Bu organizasyonda görebildiğiniz şirket yok.
            @can('organization.manage')
                <a href="{{ route('panel.companies.create') }}" style="color:var(--brand);font-weight:600">İlk şirketi açın.</a>
            @endcan
        </div>
    @else
        <div class="table-wrap">
            <table class="data">
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
                            <td><a href="{{ route('panel.companies.show', $row['company']) }}">{{ $row['company']->legal_name }}</a></td>
                            <td>@include('panel.partials.company-status', ['status' => $row['company']->status])</td>
                            <td>
                                @if ($row['kyc']['complete'])
                                    <span class="badge badge--ok">Tamam</span>
                                @else
                                    <span class="badge badge--warn">{{ count($row['kyc']['missing']) }} zorunlu belge eksik</span>
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

    @can('organization.manage')
        <div class="panel" style="margin-top:24px;max-width:520px">
            <p class="eyebrow">Organizasyon künyesi</p>
            <form method="POST" action="{{ route('panel.organization.update') }}" class="stack" style="gap:10px">
                @csrf @method('PUT')
                <label class="field"><span class="label">Organizasyon adı</span>
                    <input class="control" type="text" name="name" value="{{ old('name', $activeOrganization->name) }}" required minlength="2" maxlength="120" @error('name') aria-invalid="true" @enderror>
                </label>
                <div><button type="submit" class="btn btn--ghost">Kaydet</button></div>
            </form>
        </div>
    @endcan
@endsection
