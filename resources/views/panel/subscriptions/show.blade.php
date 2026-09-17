@extends('layouts.panel')

@section('title', 'Üyelik #'.$sub->id)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.subscriptions.index') }}">Üyelikler</a> / #{{ $sub->id }}</p>
            <h1 class="h2">{{ $sub->company->legal_name }} · {{ $sub->plan->name }}</h1>
            <p><span class="pill {{ ['active' => 'g', 'expired' => 'n', 'cancelled' => 'c'][$sub->status] }}">{{ $sub->statusLabel() }}</span> {{ $sub->starts_on->format('d.m.Y') }} – {{ $sub->ends_on->format('d.m.Y') }} @if ($sub->isActive())· {{ $sub->daysLeft() }} gün kaldı @endif</p>
        </div>
    </div>

    <div class="grid g-2-1">
        <div class="card">
            <div class="card__head"><h3>Üyelik</h3></div>
            <div class="card__body">
                <dl class="kv">
                    <dt>Şirket</dt><dd>{{ $sub->company->legal_name }}</dd>
                    <dt>Paket</dt><dd>{{ $sub->plan->name }} @if ($sub->plan->service)<span class="mini">· {{ $sub->plan->service->name }}</span>@endif</dd>
                    <dt>Tutar</dt><dd>{{ money($sub->price) }} / {{ \App\Models\Plan::PERIODS[$sub->period] ?? $sub->period }} <span class="mini">(anlık görüntü; paket bugün {{ money($sub->plan->price) }})</span></dd>
                    <dt>Dönem</dt><dd class="mono">{{ $sub->starts_on->format('d.m.Y') }} – {{ $sub->ends_on->format('d.m.Y') }}</dd>
                    <dt>Lokasyon</dt><dd>{{ $sub->location?->name ?? '—' }}</dd>
                    <dt>Yenileme</dt><dd>{{ $sub->auto_renew ? 'Dönem sonunda yenilenecek' : 'Yenilenmeyecek' }}</dd>
                    <dt>Açan</dt><dd>{{ $sub->creator?->name ?? '—' }} · {{ $sub->created_at->format('d.m.Y H:i') }}</dd>
                    @if ($sub->status === 'cancelled')<dt>İptal</dt><dd>{{ $sub->cancelled_at?->format('d.m.Y H:i') }} — {{ $sub->cancel_reason }}</dd>@endif
                    @if ($sub->note)<dt>Not</dt><dd>{{ $sub->note }}</dd>@endif
                </dl>
            </div>
        </div>

        <div class="stack" style="gap:14px">
            @can('subscription.manage')
                @if ($sub->status !== 'cancelled')
                    <div class="card">
                        <div class="card__head"><h3>Yenile</h3><span class="sub">Bitişten itibaren yeni dönem, güncel paket fiyatı</span></div>
                        <div class="card__body">
                            <form method="POST" action="{{ route('panel.subscriptions.renew', $sub) }}" class="inline-form">
                                @csrf
                                <label class="field"><span class="label">Süre (ay)</span><input class="control" type="number" name="months" value="{{ old('months', $sub->period === 'yearly' ? 12 : 1) }}" min="1" max="36" required></label>
                                <button type="submit" class="btn btn--brand">Yenile</button>
                            </form>
                            @error('months')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>
                @endif
                @if ($sub->isActive())
                    <div class="card">
                        <div class="card__head"><h3>İptal</h3></div>
                        <div class="card__body">
                            <form method="POST" action="{{ route('panel.subscriptions.cancel', $sub) }}" class="stack" style="gap:8px">
                                @csrf
                                <label class="field"><span class="label">Gerekçe</span><input class="control" type="text" name="reason" maxlength="300" required></label>
                                <div><button type="submit" class="btn btn--danger">Üyeliği iptal et</button></div>
                            </form>
                            @error('reason')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>
                @endif
            @endcan
            <div class="note">Fatura ve tahsilat bu üyeliğe Ödemeler &amp; faturalandırma modülünden bağlanır.</div>
        </div>
    </div>
@endsection
