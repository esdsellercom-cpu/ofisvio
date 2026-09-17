@extends('layouts.panel')

@section('title', 'Tahsilat & belge merkezi')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Finans · tahsilat</p>
            <h1 class="h2">Tahsilat &amp; belge merkezi</h1>
            <p>Manuel tahsilat, makbuz, geciken ödemeler ve belge şablonları — hepsi bu ekrandan. Finansal kayıt silinmez, iptal edilir; geçmiş korunur.</p>
        </div>
        <div class="panel-head__actions">
            @can('payment_allocation.manage')
                <button type="button" class="btn btn--brand" data-modal-open="#modal-payment" data-title="Manuel tahsilat">+ Manuel tahsilat</button>
                <a href="{{ route('panel.collections.index', ['sekme' => 'tahsilatlar']) }}" class="btn btn--ghost">+ Makbuz oluştur</a>
            @endcan
            <a href="{{ route('panel.collections.index', ['sekme' => 'geciken']) }}" class="btn btn--ghost">Geciken ödemeler</a>
            <a href="{{ route('panel.collections.index', ['sekme' => 'belgeler']) }}" class="btn btn--ghost">Belgeler</a>
        </div>
    </div>

    <div class="stack" style="gap:18px">
        @foreach (['payment', 'invoice', 'document'] as $errKey)
            @error($errKey)<div class="notice notice--error" role="alert"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror
        @endforeach

        <div class="kpis">
            <div class="kpi"><span class="k">Bu ay tahsil edilen</span><span class="v">{{ money($stats['revenue_month']) }}</span><span class="d">Bugün {{ money($stats['revenue_today']) }}</span></div>
            <div class="kpi {{ $stats['outstanding_count'] > 0 ? 'watch' : '' }}"><span class="k">Bekleyen tahsilat</span><span class="v">{{ money($stats['outstanding']) }}</span><span class="d">{{ $stats['outstanding_count'] }} açık fatura · {{ $stats['due_7d_count'] }} tanesinin vadesi 7 gün içinde</span></div>
            <div class="kpi {{ $stats['overdue_count'] > 0 ? 'alert' : 'ok' }}"><span class="k">Gecikmiş ödeme</span><span class="v">{{ $stats['overdue_count'] }}</span><span class="d">{{ money($stats['overdue']) }}</span></div>
            <div class="kpi {{ $subscriptions['expiring'] > 0 ? 'watch' : '' }}"><span class="k">Üyelik bitişi (30 gün)</span><span class="v">{{ $subscriptions['expiring'] }}</span><span class="d">{{ $subscriptions['active'] }} aktif · MRR {{ money($subscriptions['mrr']) }}</span></div>
        </div>

        <nav class="tabbar" aria-label="Tahsilat sekmeleri">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('panel.collections.index', ['sekme' => $key]) }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}@if ($key === 'geciken' && $stats['overdue_count'] > 0) <span class="pill c flat">{{ $stats['overdue_count'] }}</span>@endif</a>
            @endforeach
        </nav>

        @if ($tab === 'ozet')
            @include('panel.collections.partials.tab-summary')
        @elseif ($tab === 'tahsilatlar')
            @include('panel.collections.partials.tab-payments')
        @elseif ($tab === 'geciken')
            @include('panel.collections.partials.tab-overdue')
        @else
            @include('panel.collections.partials.tab-documents')
        @endif
    </div>

    @can('payment_allocation.manage')
        @include('panel.collections.partials.modal-payment')
    @endcan
@endsection
