@extends('layouts.panel')

@section('title', 'Üyelikler & paketler')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Üyelik · tüm şirketler</p>
            <h1 class="h2">Üyelikler &amp; paketler</h1>
            <p>Şirket üyelikleri (paket, dönem, bitiş). Paket tanımları <a href="{{ route('panel.plans.index') }}">Paketler</a>'de; fiyat üyelikte anlık görüntüdür.</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.plans.index') }}" class="btn btn--ghost">Paketler</a>
            @can('subscription.manage')<a href="{{ route('panel.subscriptions.create') }}" class="btn btn--brand">Yeni üyelik</a>@endcan
        </div>
    </div>

    <div class="stack" style="gap:18px">
        <div class="kpis">
            <a href="{{ route('panel.subscriptions.index', ['sekme' => 'active']) }}" class="kpi ok"><span class="k">Aktif üyelik</span><span class="v">{{ $stats['active'] }}</span><span class="d">{{ count($stats['by_plan']) }} pakette</span></a>
            <a href="{{ route('panel.subscriptions.index', ['sekme' => 'expiring']) }}" class="kpi {{ $stats['expiring'] > 0 ? 'watch' : '' }}"><span class="k">Bitişi 30 gün içinde</span><span class="v">{{ $stats['expiring'] }}</span><span class="d">Yenileme ya da iptal bekliyor</span></a>
            <div class="kpi"><span class="k">Yeni üyelik (30g)</span><span class="v">{{ $stats['new_30d'] }}</span><span class="d">Son 30 günde açılan</span></div>
            <div class="kpi"><span class="k">Aylık tekrarlayan gelir</span><span class="v">{{ money($stats['mrr']) }}</span><span class="d">Aktif üyelikler, aylığa normalize</span></div>
        </div>

        <div class="card">
            <div class="card__head">
                <nav class="tabbar" style="border:0" aria-label="Üyelik sekmeleri">
                    @foreach ($tabs as $k => $label)
                        <a href="{{ route('panel.subscriptions.index', array_filter(['sekme' => $k, 'paket' => $filters['paket'] ?? null, 'q' => $filters['q'] ?? null])) }}" @if ($tab === $k) aria-current="page" @endif>{{ $label }}@isset($tabCounts[$k]) <span class="mono mini">{{ $tabCounts[$k] }}</span>@endisset</a>
                    @endforeach
                </nav>
                <form method="GET" class="r inline-form" style="gap:6px">
                    <input type="hidden" name="sekme" value="{{ $tab }}">
                    <select class="control" name="paket" style="width:auto" data-autosubmit>
                        <option value="">Tüm paketler</option>
                        @foreach ($plans as $plan)<option value="{{ $plan->id }}" @selected((int) ($filters['paket'] ?? 0) === $plan->id)>{{ $plan->name }}</option>@endforeach
                    </select>
                    <input class="control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="şirket ara" style="width:160px">
                    <button type="submit" class="btn btn--ghost">Süz</button>
                </form>
            </div>
            @if ($rows->isEmpty())
                <div class="empty-state" style="border:0">Bu sekmede üyelik yok.</div>
            @else
                <div class="tw">
                    <table class="t">
                        <thead><tr><th>Şirket</th><th>Paket</th><th>Dönem</th><th class="num">Tutar</th><th>Bitiş</th><th>Durum</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($rows as $s)
                                <tr>
                                    <td><b>{{ $s->company->legal_name }}</b>@if ($s->location)<br><span class="mini">{{ $s->location->name }}</span>@endif</td>
                                    <td>{{ $s->plan->name }}</td>
                                    <td class="mono small">{{ $s->starts_on->format('d.m.Y') }} – {{ $s->ends_on->format('d.m.Y') }}</td>
                                    <td class="num">{{ money($s->price) }} <span class="mini">/ {{ \App\Models\Plan::PERIODS[$s->period] ?? $s->period }}</span></td>
                                    <td>@if ($s->isActive())@php($left = $s->daysLeft())<span class="pill {{ $left < 0 ? 'c' : ($left <= 30 ? 'w' : 'g') }} flat">{{ $left < 0 ? abs($left).' gün geçti' : $left.' gün' }}</span>@else <span class="mini">{{ $s->ends_on->format('d.m.Y') }}</span>@endif</td>
                                    <td><span class="pill {{ ['active' => 'g', 'expired' => 'n', 'cancelled' => 'c'][$s->status] }}">{{ $s->statusLabel() }}</span>@if ($s->isActive() && ! $s->auto_renew) <span class="mini">yenilenmez</span>@endif</td>
                                    <td class="num"><a href="{{ route('panel.subscriptions.show', $s) }}" class="btn btn--quiet">Aç</a></td>
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
