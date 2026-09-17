@extends('layouts.panel')

@section('title', 'Ödemeler & faturalandırma')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Finans · tüm şirketler</p>
            <h1 class="h2">Ödemeler &amp; faturalandırma</h1>
            <p>Şirketlere kesilen faturalar ve tahsilatlar. Numara yayınlama anında verilir; tahsilat kaydı bakiyeyi düşer, bakiye sıfırlanınca fatura ödendi olur.</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.collections.index') }}" class="btn btn--ghost">Tahsilat takibi</a>
            @can('invoice.issue')<a href="{{ route('panel.invoices.create') }}" class="btn btn--brand">Yeni fatura</a>@endcan
        </div>
    </div>

    <div class="stack" style="gap:18px">
        <div class="kpis">
            <div class="kpi"><span class="k">Aylık ciro</span><span class="v">{{ money($stats['revenue_month']) }}</span><span class="d">Bu ay kaydedilen tahsilat · bugün {{ money($stats['revenue_today']) }}</span></div>
            <div class="kpi {{ $stats['outstanding_count'] > 0 ? 'watch' : '' }}"><span class="k">Bekleyen tahsilat</span><span class="v">{{ money($stats['outstanding']) }}</span><span class="d">{{ $stats['outstanding_count'] }} açık fatura</span></div>
            <div class="kpi {{ $stats['overdue_count'] > 0 ? 'alert' : 'ok' }}"><span class="k">Gecikmiş</span><span class="v">{{ $stats['overdue_count'] }}</span><span class="d">{{ money($stats['overdue']) }} vadesi geçmiş</span></div>
            <div class="kpi"><span class="k">7 gün içinde vade</span><span class="v">{{ $stats['due_7d_count'] }}</span><span class="d">Yayınlanmış fatura</span></div>
        </div>

        <div class="card">
            <div class="card__head">
                <nav class="tabbar" style="border:0" aria-label="Fatura sekmeleri">
                    @foreach ($tabs as $k => $label)
                        <a href="{{ route('panel.invoices.index', array_filter(['sekme' => $k, 'q' => $filters['q'] ?? null])) }}" @if ($tab === $k) aria-current="page" @endif>{{ $label }}@isset($tabCounts[$k]) <span class="mono mini">{{ $tabCounts[$k] }}</span>@endisset</a>
                    @endforeach
                </nav>
                <form method="GET" class="r inline-form" style="gap:6px">
                    <input type="hidden" name="sekme" value="{{ $tab }}">
                    <input class="control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="numara, şirket, açıklama" style="width:200px">
                    <button type="submit" class="btn btn--ghost">Ara</button>
                </form>
            </div>
            @if ($rows->isEmpty())
                <div class="empty-state" style="border:0">Bu sekmede fatura yok.</div>
            @else
                <div class="tw">
                    <table class="t">
                        <thead><tr><th>Fatura</th><th>Şirket</th><th>Vade</th><th class="num">Tutar</th><th class="num">Kalan</th><th>Durum</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($rows as $inv)
                                <tr>
                                    <td><b class="mono">{{ $inv->number ?? 'taslak #'.$inv->id }}</b><br><span class="mini">{{ $inv->description }}</span></td>
                                    <td>{{ $inv->company->legal_name }}@if ($inv->subscription)<br><span class="mini">{{ $inv->subscription->plan->name }}</span>@endif</td>
                                    <td>@if ($inv->due_on)<span class="mono small">{{ $inv->due_on->format('d.m.Y') }}</span>@if ($inv->isOpen() && $inv->daysOverdue() > 0) <span class="pill c flat">{{ $inv->daysOverdue() }} gün</span>@endif @else — @endif</td>
                                    <td class="num">{{ money($inv->total) }}</td>
                                    <td class="num">{{ $inv->isOpen() ? money($inv->outstanding()) : '—' }}</td>
                                    <td><span class="pill {{ ['draft' => 'n', 'issued' => 'i', 'overdue' => 'c', 'paid' => 'g', 'cancelled' => 'n'][$inv->status] }}">{{ $inv->statusLabel() }}</span></td>
                                    <td class="num"><a href="{{ route('panel.invoices.show', $inv) }}" class="btn btn--quiet">Aç</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($rows->hasPages())<div class="card__body">{{ $rows->links() }}</div>@endif
            @endif
        </div>
    </div>
@endsection
