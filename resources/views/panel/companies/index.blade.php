@extends('layouts.panel')

@section('title', 'Şirketler')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">{{ $activeOrganization->name }}</p>
            <h1 class="h2">Şirketler</h1>
        </div>
        <div class="panel-head__actions">
            @can('organization.manage')
                <a href="{{ route('panel.companies.create') }}" class="btn btn--brand">Yeni şirket</a>
            @endcan
        </div>
    </div>

    @if ($companies->isEmpty())
        <div class="empty-state">Bu organizasyonda görebildiğiniz şirket yok.</div>
    @else
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Unvan</th>
                        <th>Vergi no</th>
                        <th>Durum</th>
                        <th>Kayıt</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($companies as $company)
                        <tr>
                            <td><a href="{{ route('panel.companies.show', $company) }}">{{ $company->legal_name }}</a></td>
                            <td class="mono">{{ $company->tax_number ?: '—' }}</td>
                            <td>@include('panel.partials.company-status', ['status' => $company->status])</td>
                            <td>{{ $company->created_at?->format('d.m.Y') }}</td>
                            <td>
                                <div class="row-actions">
                                    @canany(['kyc.view', 'kyc.view_status'], $company)
                                        <a href="{{ route('panel.companies.kyc.show', $company) }}" class="btn btn--ghost btn--pill">Belgeler</a>
                                    @endcanany
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
