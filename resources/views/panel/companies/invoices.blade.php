@extends('layouts.panel')

@section('title', 'Faturalar — '.$company->legal_name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.companies.show', $company) }}">{{ $company->legal_name }}</a> / faturalar</p>
            <h1 class="h2">Faturalar</h1>
            <p>Şirketinize kesilen faturalar ve ödemeleri. Ödeme için dekont referansınızı Ofisvio ekibine iletin; kayıt burada görünür.</p>
        </div>
    </div>

    <div class="card">
        @if ($invoices->isEmpty())
            <div class="empty-state" style="border:0">Henüz fatura yok.</div>
        @else
            <div class="tw">
                <table class="t">
                    <thead><tr><th>Fatura</th><th>Yayın</th><th>Vade</th><th class="num">Tutar</th><th class="num">Kalan</th><th>Durum</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($invoices as $inv)
                            <tr>
                                <td><b class="mono">{{ $inv->number }}</b><br><span class="mini">{{ $inv->description }}</span></td>
                                <td class="mono small">{{ $inv->issued_on?->format('d.m.Y') }}</td>
                                <td class="mono small">{{ $inv->due_on?->format('d.m.Y') }}</td>
                                <td class="num">{{ number_format($inv->total, 0, ',', '.') }} ₺</td>
                                <td class="num">{{ $inv->isOpen() ? number_format($inv->outstanding(), 0, ',', '.').' ₺' : '—' }}</td>
                                <td><span class="pill {{ ['issued' => 'i', 'overdue' => 'c', 'paid' => 'g', 'cancelled' => 'n'][$inv->status] ?? 'n' }}">{{ $inv->statusLabel() }}</span></td>
                                <td class="num"><a href="{{ route('panel.companies.invoices.show', [$company, $inv->id]) }}" class="btn btn--quiet">Aç</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
