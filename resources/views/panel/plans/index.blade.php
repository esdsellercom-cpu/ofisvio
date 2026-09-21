@extends('layouts.panel')

@section('title', 'Paketler')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.subscriptions.index') }}">Üyelikler</a> / paketler</p>
            <h1 class="h2">Paketler</h1>
            <p>Satılan üyelik paketleri. Fiyat değişince var olan üyelikler etkilenmez (anlık görüntü); üyeliği olan paket silinmez, pasife alınır.</p>
        </div>
        <div class="panel-head__actions">
            @can('subscription.manage')<a href="{{ route('panel.plans.create') }}" class="btn btn--brand">Yeni paket</a>@endcan
        </div>
    </div>

    @error('plan')<div class="notice notice--error" role="alert" style="margin-bottom:18px"><span class="notice__dot"></span><div>{{ $message }}</div></div>@enderror

    <div class="card">
        @if ($plans->isEmpty())
            <div class="empty-state" style="border:0">Henüz paket yok.</div>
        @else
            <div class="tw">
                <table class="t">
                    <thead><tr><th>Paket</th><th>Hizmet</th><th class="num">Fiyat</th><th>Dönem</th><th class="num">Üyelik</th><th>Durum</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($plans as $plan)
                            <tr>
                                <td><b>{{ $plan->name }}</b>@if ($plan->summary)<br><span class="mini">{{ $plan->summary }}</span>@endif</td>
                                <td>{{ $plan->service?->name ?? '—' }}@if ($plan->space_kind)<br><span class="tag">{{ \App\Models\Space::KINDS[$plan->space_kind] ?? $plan->space_kind }}</span>@endif</td>
                                <td class="num">{{ money($plan->price) }}</td>
                                <td>{{ $plan->periodLabel() }}</td>
                                <td class="num">{{ $plan->subscriptions_count }}</td>
                                <td>@if ($plan->is_active)<span class="pill g">Aktif</span>@else<span class="pill n">Pasif</span>@endif</td>
                                <td class="num">
                                    @can('subscription.manage')
                                        <div class="row-actions">
                                            <a href="{{ route('panel.plans.edit', $plan) }}" class="btn btn--quiet">Düzenle</a>
                                            @if ($plan->subscriptions_count === 0)
                                                <form method="POST" action="{{ route('panel.plans.destroy', $plan) }}" data-confirm="Paket silinsin mi?">@csrf @method('DELETE')<button type="submit" class="btn btn--quiet" style="color:var(--crit)">Sil</button></form>
                                            @endif
                                        </div>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
