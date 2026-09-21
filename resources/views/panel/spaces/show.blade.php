@extends('layouts.panel')

@section('title', $space->name.' — '.$space->location->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.spaces.index') }}">Alanlar</a> / {{ $space->location->name }}</p>
            <h1 class="h2">{{ $space->name }} <span class="tag" style="vertical-align:middle">{{ $space->kindLabel() }}</span></h1>
            <p>@if (! $space->is_active)<span class="pill n">Pasif</span>@elseif ($space->isFull())<span class="pill w">Dolu</span>@else<span class="pill g">Boş</span>@endif {{ $space->occupied() }} / {{ $space->slots() }} yer · {{ money($space->monthly_price) }}/ay{{ $space->floor ? ' · Kat '.$space->floor : '' }}{{ $space->zone ? ' · '.$space->zone : '' }}@if ($space->kind === 'office') · {{ $space->capacity }} kişilik @endif</p>
        </div>
        <div class="panel-head__actions">
            @can('geo.edit')<a href="{{ route('panel.geo.spaces.index', $space->location) }}" class="btn btn--ghost">Envanteri düzenle</a>@endcan
        </div>
    </div>

    @error('assignment')<div class="notice notice--error" role="alert" style="margin-bottom:18px"><span class="notice__dot"></span><div>{{ $message }}</div></div>@enderror

    <div class="grid g-2-1">
        <div class="card">
            <div class="card__head"><h3>Tahsisler</h3><span class="sub">Aktif önce, sonra geçmiş</span></div>
            @if ($assignments->isEmpty())
                <div class="empty-state" style="border:0">Henüz tahsis yok.</div>
            @else
                <div class="tw">
                    <table class="t">
                        <thead><tr><th>Şirket</th><th>Üye</th><th>Dönem</th><th>Üyelik</th><th>Durum</th>@if ($canManage)<th></th>@endif</tr></thead>
                        <tbody>
                            @foreach ($assignments as $a)
                                <tr>
                                    <td><b>{{ $a->company->legal_name }}</b>@if ($a->note)<br><span class="mini">{{ $a->note }}</span>@endif</td>
                                    <td class="small">{{ $a->user?->name ?? '—' }}</td>
                                    <td class="mono small">{{ $a->starts_on->format('d.m.Y') }} – {{ $a->ends_on?->format('d.m.Y') ?? 'süresiz' }}</td>
                                    <td class="small">{{ $a->subscription?->plan?->name ?? '—' }}</td>
                                    <td>@if ($a->isActive())<span class="pill g">Aktif</span>@else<span class="pill n">Sona erdi</span>@endif</td>
                                    @if ($canManage)
                                        <td class="num">
                                            @if ($a->isActive())
                                                <form method="POST" action="{{ route('panel.spaces.end', [$space->id, $a->id]) }}" data-confirm="Tahsis sonlandırılsın mı?">@csrf<button type="submit" class="btn btn--quiet" style="color:var(--crit)">Sonlandır</button></form>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="stack" style="gap:14px">
            @if ($canManage && $space->is_active && ! $space->isFull())
                <div class="card">
                    <div class="card__head"><h3>Tahsis et</h3><span class="sub">{{ $space->slots() - $space->occupied() }} boş yer</span></div>
                    <div class="card__body">
                        <form method="GET" action="{{ route('panel.spaces.show', $space->id) }}" class="stack" style="gap:8px;margin-bottom:8px">
                            <label class="field"><span class="label">Şirket</span>
                                <select class="control" name="sirket" data-autosubmit @error('company_id') aria-invalid="true" @enderror>
                                    <option value="">Seçin</option>
                                    @foreach ($companies as $c)<option value="{{ $c->id }}" @selected($selectedCompany && $selectedCompany->id === $c->id)>{{ $c->legal_name }}</option>@endforeach
                                </select>
                            </label>
                            <noscript><button type="submit" class="btn btn--ghost">Şirketi seç</button></noscript>
                        </form>
                        @error('company_id')<span class="field-error">{{ $message }}</span>@enderror
                        @if ($selectedCompany)
                            <form method="POST" action="{{ route('panel.spaces.assign', $space->id) }}" class="stack" style="gap:8px">
                                @csrf
                                <input type="hidden" name="company_id" value="{{ $selectedCompany->id }}">
                                <label class="field"><span class="label">Üye (isteğe bağlı)</span>
                                    <select class="control" name="user_id"><option value="">—</option>@foreach ($members as $m)<option value="{{ $m->user_id }}" @selected((int) old('user_id') === (int) $m->user_id)>{{ $m->user->name }}</option>@endforeach</select>
                                    @error('user_id')<span class="field-error">{{ $message }}</span>@enderror
                                </label>
                                <label class="field"><span class="label">Üyelik (isteğe bağlı)</span>
                                    <select class="control" name="subscription_id"><option value="">—</option>@foreach ($subscriptions as $s)<option value="{{ $s->id }}" @selected((int) old('subscription_id') === $s->id)>{{ $s->plan->name }} · {{ $s->ends_on->format('d.m.Y') }}</option>@endforeach</select>
                                </label>
                                <div class="grid g2">
                                    <label class="field"><span class="label">Başlangıç</span><input class="control" type="date" name="starts_on" value="{{ old('starts_on', now()->toDateString()) }}" required></label>
                                    <label class="field"><span class="label">Bitiş (boş = süresiz)</span><input class="control" type="date" name="ends_on" value="{{ old('ends_on') }}"></label>
                                </div>
                                <label class="field"><span class="label">Not</span><input class="control" type="text" name="note" value="{{ old('note') }}" maxlength="300"></label>
                                <div><button type="submit" class="btn btn--brand">Tahsis et</button></div>
                            </form>
                        @endif
                    </div>
                </div>
            @elseif ($canManage && ! $space->is_active)
                <div class="note w">Alan pasif; tahsis için lokasyon envanterinden aktifleştirin.</div>
            @endif
            <div class="card">
                <div class="card__head"><h3>Alan</h3></div>
                <div class="card__body">
                    <dl class="kv">
                        <dt>Lokasyon</dt><dd>{{ $space->location->name }}</dd>
                        <dt>Tür</dt><dd>{{ $space->kindLabel() }}</dd>
                        <dt>Kapasite</dt><dd>{{ $space->capacity }} {{ $space->kind === 'desk_flex' ? 'eşzamanlı üye' : 'kişi' }}</dd>
                        <dt>Aylık ücret</dt><dd>{{ money($space->monthly_price) }}</dd>
                        <dt>Durum</dt><dd>{{ $space->operationalLabel() }}@if ($space->isUnderMaintenance()) · {{ $space->maintenance_until?->format('d.m.Y') }} — {{ $space->maintenance_note }}@endif</dd>
                        @if ($space->amenityList() !== [])<dt>Olanaklar</dt><dd>{{ implode(', ', $space->amenityList()) }}</dd>@endif
                        @if ($space->notes)<dt>Not</dt><dd>{{ $space->notes }}</dd>@endif
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
