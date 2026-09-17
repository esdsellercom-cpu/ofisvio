@extends('layouts.panel')

@section('title', 'Tahsilat & üyelik takibi')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Finans · takip</p>
            <h1 class="h2">Tahsilat &amp; üyelik takibi</h1>
            <p>Vadesi geçen ve yaklaşan faturalar, bitişi yaklaşan üyelikler, ay tahsilatı — hepsi canlı toplam.</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.invoices.index') }}" class="btn btn--ghost">Faturalar</a>
            @can('subscription.view')<a href="{{ route('panel.subscriptions.index', ['sekme' => 'expiring']) }}" class="btn btn--ghost">Üyelikler</a>@endcan
        </div>
    </div>

    <div class="stack" style="gap:18px">
        <div class="kpis">
            <div class="kpi"><span class="k">Bu ay tahsil edilen</span><span class="v">{{ money($stats['revenue_month']) }}</span><span class="d">Bugün {{ money($stats['revenue_today']) }}</span></div>
            <div class="kpi {{ $stats['outstanding_count'] > 0 ? 'watch' : '' }}"><span class="k">Bekleyen tahsilat</span><span class="v">{{ money($stats['outstanding']) }}</span><span class="d">{{ $stats['outstanding_count'] }} açık fatura · {{ $stats['due_7d_count'] }} tanesinin vadesi 7 gün içinde</span></div>
            <div class="kpi {{ $stats['overdue_count'] > 0 ? 'alert' : 'ok' }}"><span class="k">Gecikmiş ödeme</span><span class="v">{{ $stats['overdue_count'] }}</span><span class="d">{{ money($stats['overdue']) }}</span></div>
            <div class="kpi {{ $subscriptions['expiring'] > 0 ? 'watch' : '' }}"><span class="k">Üyelik bitişi (30 gün)</span><span class="v">{{ $subscriptions['expiring'] }}</span><span class="d">{{ $subscriptions['active'] }} aktif · MRR {{ money($subscriptions['mrr']) }}</span></div>
        </div>

        <div class="grid g-2-1">
            <div class="card">
                <div class="card__head"><h3>Açık faturalar</h3><span class="sub">Gecikmiş önce, vade sırasıyla</span></div>
                @if ($open->isEmpty())
                    <div class="empty-state" style="border:0">Tahsilat bekleyen fatura yok.</div>
                @else
                    <div class="tw"><table class="t"><thead><tr><th>Fatura</th><th>Şirket</th><th>Vade</th><th class="num">Kalan</th><th></th></tr></thead><tbody>
                        @foreach ($open as $inv)
                            <tr>
                                <td class="mono">{{ $inv->number }}</td>
                                <td><b>{{ $inv->company->legal_name }}</b><br><span class="mini">{{ $inv->description }}</span></td>
                                <td>@php($d = $inv->daysOverdue())@if ($inv->status === 'overdue')<span class="pill c flat">{{ $d }} gün gecikti</span>@elseif ($d >= 0)<span class="pill w flat">bugün / vadesi geçti</span>@else<span class="pill i flat">{{ abs($d) }} gün kaldı</span>@endif</td>
                                <td class="num">{{ money($inv->outstanding()) }}</td>
                                <td class="num"><a href="{{ route('panel.invoices.show', $inv) }}" class="btn btn--quiet">Aç</a></td>
                            </tr>
                        @endforeach
                    </tbody></table></div>
                @endif
            </div>

            <div class="stack" style="gap:14px">
                <div class="card">
                    <div class="card__head"><h3>Aylık tahsilat</h3><span class="sub">Son 6 ay</span></div>
                    <div class="card__body">
                        @php($max = max(1, max(array_column($monthly, 'amount'))))
                        @foreach ($monthly as $m)
                            <div class="barrow"><span class="lbl mono">{{ $m['month'] }}</span><span class="meter"><i class="g" style="width:{{ round($m['amount'] / $max * 100) }}%"></i></span><span class="val">{{ money($m['amount']) }}</span></div>
                        @endforeach
                    </div>
                </div>
                <div class="card">
                    <div class="card__head"><h3>Yaklaşan üyelik bitişleri</h3><span class="sub">30 gün</span></div>
                    @if ($expiring->isEmpty())
                        <div class="empty-state" style="border:0">Bitişi yaklaşan üyelik yok.</div>
                    @else
                        <div class="rows">
                            @foreach ($expiring as $s)
                                <a href="{{ route('panel.subscriptions.show', $s) }}" class="row">
                                    <div class="main-t"><b>{{ $s->company->legal_name }}</b><span>{{ $s->plan->name }} · {{ $s->ends_on->format('d.m.Y') }}</span></div>
                                    <span class="rt"><span class="pill w flat">{{ $s->daysLeft() }} gün</span></span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
