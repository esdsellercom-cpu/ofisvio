@extends('layouts.panel')

@section('title', 'KYC kuyruğu')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">{{ $activeOrganization->name }}</p>
            <h1 class="h2">KYC inceleme kuyruğu</h1>
        </div>
    </div>

    @if ($documents->isEmpty())
        <div class="empty-state">Bu organizasyonda inceleme bekleyen belge yok.</div>
    @else
        <p class="body-muted" style="margin:0 0 18px">En eski yükleme en üstte. Belge içeriği için JIT erişimi şirket sayfasından açılır.</p>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Şirket</th><th>Belge</th><th>Durum</th><th>Bekliyor</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($documents as $doc)
                        <tr>
                            <td><a href="{{ route('panel.companies.show', $doc->company) }}">{{ $doc->company->legal_name }}</a></td>
                            <td>{{ $doc->type->label() }}</td>
                            <td><span class="badge badge--info">{{ $doc->status->label() }}</span></td>
                            <td class="small">{{ $doc->created_at?->diffForHumans(null, true) }}</td>
                            <td><div class="row-actions"><a href="{{ route('panel.companies.kyc.show', $doc->company) }}" class="btn btn--ghost btn--pill">İncele</a></div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
