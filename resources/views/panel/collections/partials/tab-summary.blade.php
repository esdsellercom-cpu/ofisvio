{{-- Özet sekmesi (faz 39c içeriği): açık faturalar, aylık tahsilat, yaklaşan üyelik bitişleri --}}
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
                        <td class="num">{{ money($inv->outstanding(), $inv->currency) }}</td>
                        <td class="num"><span style="display:inline-flex;gap:4px">
                            @can('payment_allocation.manage')<button type="button" class="btn btn--quiet" data-modal-open="#modal-payment" data-title="Tahsilat · {{ $inv->number }}" data-fill="{{ json_encode(['company_id' => $inv->company_id, 'invoice_id' => $inv->id, 'amount' => \App\Support\Money::major($inv->outstanding()), 'description' => $inv->description, 'paid_on' => now()->toDateString()]) }}">Tahsilat</button>@endcan
                            <a href="{{ route('panel.invoices.show', $inv) }}" class="btn btn--quiet">Aç</a>
                        </span></td>
                    </tr>
                @endforeach
            </tbody></table></div>
        @endif
    </div>

    <div class="stack" style="gap:14px">
        <div class="card">
            <div class="card__head"><h3>Aylık tahsilat</h3><span class="sub">Son 6 ay · iptaller hariç</span></div>
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
