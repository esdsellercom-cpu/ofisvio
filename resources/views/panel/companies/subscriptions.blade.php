@extends('layouts.panel')

@section('title', 'Üyelik — '.$company->legal_name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.companies.show', $company) }}">{{ $company->legal_name }}</a> / üyelik</p>
            <h1 class="h2">Üyelik</h1>
            <p>Şirketinizin paket üyelikleri. Yenileme ve değişiklik için Ofisvio ekibiyle iletişime geçin; fatura ve ödemeler ilgili sekmede.</p>
        </div>
    </div>

    <div class="card">
        @if ($subs->isEmpty())
            <div class="empty-state" style="border:0">Henüz üyelik yok.</div>
        @else
            <div class="tw">
                <table class="t">
                    <thead><tr><th>Paket</th><th>Dönem</th><th class="num">Tutar</th><th>Lokasyon</th><th>Durum</th></tr></thead>
                    <tbody>
                        @foreach ($subs as $s)
                            <tr>
                                <td><b>{{ $s->plan->name }}</b>@if ($s->plan->summary)<br><span class="mini">{{ $s->plan->summary }}</span>@endif</td>
                                <td class="mono small">{{ $s->starts_on->format('d.m.Y') }} – {{ $s->ends_on->format('d.m.Y') }}</td>
                                <td class="num">{{ money($s->price) }} / {{ \App\Models\Plan::PERIODS[$s->period] ?? $s->period }}</td>
                                <td>{{ $s->location?->name ?? '—' }}</td>
                                <td><span class="pill {{ ['active' => 'g', 'expired' => 'n', 'cancelled' => 'c'][$s->status] }}">{{ $s->statusLabel() }}</span>@if ($s->isActive()) <span class="mini">{{ $s->daysLeft() }} gün</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
