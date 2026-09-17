{{-- Tahsilatlar sekmesi (faz 47): kayıtlar, iptal (silme yok), makbuz --}}
<form method="GET" class="inv-toolbar">
    <input type="hidden" name="sekme" value="tahsilatlar">
    <select class="control" name="yontem" style="max-width:180px" onchange="this.form.requestSubmit()">
        <option value="">Tüm yöntemler</option>
        @foreach ($methods as $k => $label)<option value="{{ $k }}" @selected(request('yontem') === $k)>{{ $label }}</option>@endforeach
    </select>
    <select class="control" name="durum" style="max-width:160px" onchange="this.form.requestSubmit()">
        <option value="">Kayıtlı + iptal</option>
        <option value="recorded" @selected(request('durum') === 'recorded')>Yalnız kayıtlı</option>
        <option value="cancelled" @selected(request('durum') === 'cancelled')>Yalnız iptal</option>
    </select>
    <span class="spacer"></span>
    <input class="control" type="search" name="q" value="{{ $q }}" placeholder="Fatura no, referans, açıklama…" style="max-width:260px">
    <button type="submit" class="btn btn--ghost">Ara</button>
</form>
<div class="card">
    <div class="card__head"><h3>Tahsilatlar</h3><span class="sub">{{ $payments->count() }} kayıt · makbuz her satırdan</span></div>
    @if ($payments->isEmpty())
        <div class="empty-state" style="border:0">Tahsilat kaydı yok.@can('payment_allocation.manage') <button type="button" class="btn btn--quiet" data-modal-open="#modal-payment" data-title="Manuel tahsilat">+ Manuel tahsilat</button>@endcan</div>
    @else
        <div class="tw"><table class="t">
            <thead><tr><th>Tarih</th><th>Müşteri</th><th>Fatura</th><th>Hizmet / açıklama</th><th>Yöntem</th><th class="num">Tutar</th><th>Durum</th><th>Makbuz</th><th></th></tr></thead>
            <tbody>
                @foreach ($payments as $p)
                    <tr style="{{ $p->isCancelled() ? 'opacity:.6' : '' }}">
                        <td class="mono small">{{ $p->paid_on->format('d.m.Y') }}</td>
                        <td><b>{{ $p->company?->legal_name ?? '—' }}</b><span class="mini" style="display:block">{{ $p->recorder?->name ?? 'Sistem' }}</span></td>
                        <td class="mono small">@if ($p->invoice)<a href="{{ route('panel.invoices.show', $p->invoice) }}">{{ $p->invoice->number }}</a>@else — @endif</td>
                        <td class="small">{{ $p->description ?? $p->invoice?->description ?? '—' }}@if ($p->reference)<br><span class="mini mono">{{ $p->reference }}</span>@endif @if ($p->note)<br><span class="mini">{{ $p->note }}</span>@endif</td>
                        <td>{{ $p->methodLabel() }}@if ($p->isCash()) <span class="pill a flat">nakit</span>@endif</td>
                        <td class="num">{{ money($p->amount, $p->currency) }}</td>
                        <td>@if ($p->isCancelled())<span class="pill n">İptal</span><span class="mini" style="display:block">{{ $p->cancel_reason }}</span>@else<span class="pill g">Kayıtlı</span>@endif</td>
                        <td class="small">@if ($p->receipt)<a href="{{ route('panel.collections.documents.show', $p->receipt) }}" class="mono">{{ $p->receipt->number }}</a>@else — @endif</td>
                        <td class="num">
                            @can('payment_allocation.manage')
                                @if (! $p->isCancelled())
                                    <span style="display:inline-flex;gap:4px">
                                        @if ($p->receipt)
                                            <a href="{{ route('panel.collections.documents.show', $p->receipt) }}" class="btn btn--quiet">Makbuz</a>
                                        @else
                                            <form method="POST" action="{{ route('panel.collections.payments.receipt', $p->id) }}">@csrf<button type="submit" class="btn btn--quiet">Makbuz oluştur</button></form>
                                        @endif
                                        <details class="menu"><summary class="btn btn--quiet">İptal</summary>
                                            <form method="POST" action="{{ route('panel.collections.payments.cancel', $p->id) }}" class="menu__list" style="min-width:250px;padding:8px;gap:6px" onsubmit="return confirm('Tahsilat iptal edilsin mi? Fatura bakiyesi geri alınır, kayıt geçmişte kalır.')">@csrf
                                                <input class="control" type="text" name="reason" placeholder="İptal gerekçesi (zorunlu)" required minlength="5" maxlength="200">
                                                <button type="submit" class="btn btn--danger" style="justify-content:center">Tahsilatı iptal et</button>
                                            </form>
                                        </details>
                                    </span>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @endif
</div>
