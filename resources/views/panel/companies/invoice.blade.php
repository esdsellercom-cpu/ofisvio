@extends('layouts.panel')

@section('title', 'Fatura '.$invoice->number)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.companies.invoices.index', $company) }}">Faturalar</a> / {{ $invoice->number }}</p>
            <h1 class="h2">{{ number_format($invoice->total, 0, ',', '.') }} ₺ · {{ $invoice->statusLabel() }}</h1>
            <p>{{ $invoice->description }}</p>
        </div>
    </div>

    <div class="grid g-2-1">
        <div class="card">
            <div class="card__head"><h3>Fatura</h3><span class="sub mono">{{ $invoice->number }}</span></div>
            <div class="card__body">
                <dl class="kv">
                    <dt>Yayın</dt><dd>{{ $invoice->issued_on?->format('d.m.Y') }}</dd>
                    <dt>Vade</dt><dd>{{ $invoice->due_on?->format('d.m.Y') }}</dd>
                    @if ($invoice->subscription)<dt>Üyelik</dt><dd>{{ $invoice->subscription->plan->name }}</dd>@endif
                    <dt>Ara toplam</dt><dd class="mono">{{ number_format($invoice->subtotal, 0, ',', '.') }} ₺</dd>
                    <dt>KDV (%{{ $invoice->tax_rate }})</dt><dd class="mono">{{ number_format($invoice->tax_amount, 0, ',', '.') }} ₺</dd>
                    <dt>Toplam</dt><dd class="mono"><b>{{ number_format($invoice->total, 0, ',', '.') }} ₺</b></dd>
                    <dt>Ödenen</dt><dd class="mono">{{ number_format($invoice->paid_amount, 0, ',', '.') }} ₺ @if ($invoice->isOpen())· kalan <b>{{ number_format($invoice->outstanding(), 0, ',', '.') }} ₺</b>@endif</dd>
                </dl>
            </div>
        </div>
        <div class="card">
            <div class="card__head"><h3>Ödemeler</h3></div>
            @if ($invoice->payments->isEmpty())
                <div class="empty-state" style="border:0">Henüz ödeme kaydı yok.</div>
            @else
                <div class="rows">
                    @foreach ($invoice->payments->sortByDesc('paid_on') as $p)
                        <div class="row"><div class="main-t"><b>{{ number_format($p->amount, 0, ',', '.') }} ₺</b><span>{{ $p->methodLabel() }} · {{ $p->paid_on->format('d.m.Y') }}@if ($p->reference) · {{ $p->reference }}@endif</span></div></div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
