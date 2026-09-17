{{-- Geciken ödemeler sekmesi (faz 47) --}}
<div class="card">
    <div class="card__head"><h3>Geciken ödemeler</h3><span class="sub">{{ $overdue->count() }} fatura · vadesi geçmiş, en eski önce</span></div>
    @if ($overdue->isEmpty())
        <div class="empty-state" style="border:0">Vadesi geçmiş fatura yok.</div>
    @else
        <div class="tw"><table class="t">
            <thead><tr><th>Müşteri</th><th>Fatura</th><th>Vade tarihi</th><th class="num">Gecikme</th><th class="num">Toplam</th><th class="num">Ödenen</th><th class="num">Kalan</th><th></th></tr></thead>
            <tbody>
                @foreach ($overdue as $inv)
                    <tr>
                        <td><b>{{ $inv->company->legal_name }}</b><span class="mini" style="display:block">{{ $inv->description }}</span></td>
                        <td class="mono small"><a href="{{ route('panel.invoices.show', $inv) }}">{{ $inv->number }}</a></td>
                        <td class="mono small">{{ $inv->due_on?->format('d.m.Y') }}</td>
                        <td class="num"><span class="pill c flat">{{ $inv->daysOverdue() }} gün</span></td>
                        <td class="num">{{ money($inv->total, $inv->currency) }}</td>
                        <td class="num">{{ money($inv->paid_amount, $inv->currency) }}</td>
                        <td class="num"><b>{{ money($inv->outstanding(), $inv->currency) }}</b></td>
                        <td class="num">
                            @can('payment_allocation.manage')
                                <span style="display:inline-flex;gap:4px">
                                    <button type="button" class="btn btn--quiet" data-modal-open="#modal-payment" data-title="Tahsilat · {{ $inv->number }}" data-fill="{{ json_encode(['company_id' => $inv->company_id, 'invoice_id' => $inv->id, 'amount' => \App\Support\Money::major($inv->outstanding()), 'description' => $inv->description, 'paid_on' => now()->toDateString()]) }}">Tahsilat</button>
                                    <form method="POST" action="{{ route('panel.collections.invoices.notice', $inv->id) }}">@csrf<button type="submit" class="btn btn--quiet">Geciken ödeme belgesi oluştur</button></form>
                                </span>
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @endif
</div>
