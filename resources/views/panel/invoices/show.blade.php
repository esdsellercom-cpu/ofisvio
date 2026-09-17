@extends('layouts.panel')

@section('title', 'Fatura '.($invoice->number ?? '#'.$invoice->id))

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.invoices.index') }}">Faturalar</a> / {{ $invoice->number ?? 'taslak #'.$invoice->id }}</p>
            <h1 class="h2">{{ $invoice->company->legal_name }} · {{ money($invoice->total) }}</h1>
            <p><span class="pill {{ ['draft' => 'n', 'issued' => 'i', 'overdue' => 'c', 'paid' => 'g', 'cancelled' => 'n'][$invoice->status] }}">{{ $invoice->statusLabel() }}</span> {{ $invoice->description }}</p>
        </div>
        <div class="panel-head__actions">
            @can('invoice.issue')
                @if ($invoice->status === 'draft')
                    <form method="POST" action="{{ route('panel.invoices.issue', $invoice) }}">@csrf<button type="submit" class="btn btn--brand">Yayınla</button></form>
                @endif
            @endcan
        </div>
    </div>

    @error('invoice')<div class="notice notice--error" role="alert" style="margin-bottom:18px"><span class="notice__dot"></span><div>{{ $message }}</div></div>@enderror

    <div class="grid g-2-1">
        <div class="stack" style="gap:14px">
            <div class="card">
                <div class="card__head"><h3>Fatura</h3>@if ($invoice->number)<span class="sub mono">{{ $invoice->number }}</span>@endif</div>
                <div class="card__body">
                    <dl class="kv">
                        <dt>Şirket</dt><dd>{{ $invoice->company->legal_name }}</dd>
                        <dt>Açıklama</dt><dd>{{ $invoice->description }}</dd>
                        @if ($invoice->subscription)<dt>Üyelik</dt><dd><a href="{{ route('panel.subscriptions.show', $invoice->subscription) }}">{{ $invoice->subscription->plan->name }}</a> · {{ $invoice->subscription->starts_on->format('d.m.Y') }}–{{ $invoice->subscription->ends_on->format('d.m.Y') }}</dd>@endif
                        <dt>Ara toplam</dt><dd class="mono">{{ money($invoice->subtotal) }}</dd>
                        <dt>KDV (%{{ $invoice->tax_rate }})</dt><dd class="mono">{{ money($invoice->tax_amount) }}</dd>
                        <dt>Toplam</dt><dd class="mono"><b>{{ money($invoice->total) }}</b></dd>
                        <dt>Tahsil edilen</dt><dd class="mono">{{ money($invoice->paid_amount) }} @if ($invoice->isOpen())· kalan <b>{{ money($invoice->outstanding()) }}</b>@endif</dd>
                        <dt>Yayın</dt><dd>{{ $invoice->issued_on?->format('d.m.Y') ?? '— (taslak)' }}</dd>
                        <dt>Vade</dt><dd>{{ $invoice->due_on?->format('d.m.Y') ?? '—' }} @if ($invoice->isOpen() && $invoice->daysOverdue() > 0)<span class="pill c flat">{{ $invoice->daysOverdue() }} gün gecikti</span>@endif</dd>
                        @if ($invoice->paid_at)<dt>Ödendi</dt><dd>{{ $invoice->paid_at->format('d.m.Y H:i') }}</dd>@endif
                        @if ($invoice->status === 'cancelled')<dt>İptal</dt><dd>{{ $invoice->cancelled_at?->format('d.m.Y H:i') }} — {{ $invoice->cancel_reason }}</dd>@endif
                        <dt>Oluşturan</dt><dd>{{ $invoice->creator?->name ?? '—' }} · {{ $invoice->created_at->format('d.m.Y H:i') }}</dd>
                        @if ($invoice->note)<dt>Not</dt><dd>{{ $invoice->note }}</dd>@endif
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card__head"><h3>Tahsilatlar</h3><span class="sub">{{ $invoice->payments->count() }} kayıt</span></div>
                @if ($invoice->payments->isEmpty())
                    <div class="empty-state" style="border:0">Henüz tahsilat yok.</div>
                @else
                    <div class="tw"><table class="t"><thead><tr><th>Tarih</th><th class="num">Tutar</th><th>Yöntem</th><th>Referans</th><th>Kaydeden</th></tr></thead><tbody>
                        @foreach ($invoice->payments->sortByDesc('paid_on') as $p)
                            <tr><td class="mono small">{{ $p->paid_on->format('d.m.Y') }}</td><td class="num">{{ money($p->amount) }}</td><td>{{ $p->methodLabel() }}</td><td class="small">{{ $p->reference ?? '—' }}@if ($p->note)<br><span class="mini">{{ $p->note }}</span>@endif</td><td class="small">{{ $p->recorder?->name ?? 'Sistem' }}</td></tr>
                        @endforeach
                    </tbody></table></div>
                @endif
            </div>
        </div>

        <div class="stack" style="gap:14px">
            @can('payment_allocation.manage')
                @if ($invoice->isOpen())
                    <div class="card">
                        <div class="card__head"><h3>Tahsilat kaydet</h3><span class="sub">Kalan {{ money($invoice->outstanding()) }}</span></div>
                        <div class="card__body">
                            <form method="POST" action="{{ route('panel.invoices.payment', $invoice) }}" class="stack" style="gap:8px">
                                @csrf
                                <label class="field"><span class="label">Tutar (₺)</span><input class="control" type="number" step="0.01" name="amount" value="{{ old('amount', \App\Support\Money::major($invoice->outstanding())) }}" min="0.01" max="{{ \App\Support\Money::major($invoice->outstanding()) }}" required @error('amount') aria-invalid="true" @enderror>@error('amount')<span class="field-error">{{ $message }}</span>@enderror</label>
                                <label class="field"><span class="label">Yöntem</span><select class="control" name="method">@foreach ($methods as $k => $label)<option value="{{ $k }}" @selected(old('method', 'transfer') === $k)>{{ $label }}</option>@endforeach</select></label>
                                <label class="field"><span class="label">Tarih</span><input class="control" type="date" name="paid_on" value="{{ old('paid_on', now()->toDateString()) }}" required></label>
                                <label class="field"><span class="label">Referans (dekont / işlem no)</span><input class="control" type="text" name="reference" value="{{ old('reference') }}" maxlength="100"></label>
                                <label class="field"><span class="label">Not</span><input class="control" type="text" name="note" value="{{ old('note') }}" maxlength="300"></label>
                                <div><button type="submit" class="btn btn--brand">Kaydet</button></div>
                            </form>
                        </div>
                    </div>
                @endif
            @endcan
            @if ($invoice->status !== 'paid' && $invoice->status !== 'cancelled')
                @if ($canCancel)
                    <div class="card">
                        <div class="card__head"><h3>İptal (JIT)</h3><span class="sub">Süreli erişim açık</span></div>
                        <div class="card__body">
                            <form method="POST" action="{{ route('panel.invoices.cancel', $invoice) }}" class="stack" style="gap:8px">
                                @csrf
                                <label class="field"><span class="label">Gerekçe</span><input class="control" type="text" name="reason" maxlength="300" required></label>
                                @error('reason')<span class="field-error">{{ $message }}</span>@enderror
                                <div><button type="submit" class="btn btn--danger">Faturayı iptal et</button></div>
                            </form>
                        </div>
                    </div>
                @elseif ($mayRequestCancel)
                    <div class="card">
                        <div class="card__head"><h3>İptal için erişim iste</h3><span class="sub">invoice.cancel JIT ister; denetim kaydına düşer</span></div>
                        <div class="card__body">
                            <form method="POST" action="{{ route('panel.invoices.jit', $invoice) }}" class="stack" style="gap:8px">
                                @csrf
                                <label class="field"><span class="label">Gerekçe (en az 10 karakter)</span><input class="control" type="text" name="reason" minlength="10" maxlength="500" required></label>
                                <label class="field"><span class="label">Süre (dk)</span><input class="control" type="number" name="ttl_minutes" value="30" min="5" max="240" required></label>
                                @error('reason')<span class="field-error">{{ $message }}</span>@enderror
                                <div><button type="submit" class="btn btn--ghost">Erişim iste</button></div>
                            </form>
                        </div>
                    </div>
                @endif
            @endif
            <div class="note">Ödeme sağlayıcısı (iyzico) geçitten açıldığında webhook aynı tahsilat kaydını otomatik düşer; manuel kayıt akışı değişmez.</div>
        </div>
    </div>
@endsection
