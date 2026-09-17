@extends('layouts.panel')

@section('title', 'Arama')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Panelde ara</p>
            <h1 class="h2">{{ $q !== '' ? '“'.$q.'”' : 'Arama' }}</h1>
            <p>Rezervasyon (referans, müşteri, telefon), talep, şirket ve kullanıcı — yalnız yetkiniz olan kümeler.</p>
        </div>
    </div>

    @php($any = collect($results)->filter(fn ($set) => $set !== null && $set->isNotEmpty())->isNotEmpty())
    @php($searched = collect($results)->filter(fn ($set) => $set !== null)->keys())

    @if (mb_strlen($q) < 2)
        <div class="empty-state">En az 2 karakter yazın.</div>
    @elseif (! $any)
        @php($names = $searched->map(fn ($k) => ['bookings' => 'rezervasyon', 'leads' => 'talep', 'companies' => 'şirket', 'users' => 'kullanıcı'][$k])->implode(', '))
        <div class="empty-state">“{{ $q }}” için sonuç yok{{ $names !== '' ? ' ('.$names.' kümelerinde arandı)' : '' }}.</div>
    @else
        <div class="grid g2">
            @if ($results['bookings']?->isNotEmpty())
                <div class="card">
                    <div class="card__head"><h3>Rezervasyonlar</h3><span class="sub">{{ $results['bookings']->count() }} sonuç</span><span class="r"><a href="{{ route('panel.bookings.index', ['q' => $q]) }}" class="btn btn--quiet">Tümü</a></span></div>
                    <div class="rows">
                        @foreach ($results['bookings'] as $b)
                            <a href="{{ route('panel.bookings.show', [$b->location, $b]) }}" class="row">
                                <div class="main-t"><b>{{ $b->reference }} · {{ $b->customer_name }}</b><span>{{ $b->location->name }} · {{ $b->room->name }} · {{ $b->starts_at->format('d.m.Y H:i') }}</span></div>
                                <span class="pill {{ ['ok' => 'g', 'warn' => 'w', 'muted' => 'n'][$b->status->badge()] }}">{{ $b->status->label() }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($results['leads']?->isNotEmpty())
                <div class="card">
                    <div class="card__head"><h3>Talepler</h3><span class="sub">{{ $results['leads']->count() }} sonuç</span><span class="r"><a href="{{ route('panel.leads.index', ['q' => $q]) }}" class="btn btn--quiet">Tümü</a></span></div>
                    <div class="rows">
                        @foreach ($results['leads'] as $lead)
                            <a href="{{ route('panel.leads.show', $lead) }}" class="row">
                                <div class="main-t"><b>{{ $lead->name }}</b><span>{{ $lead->email }} · {{ $lead->created_at->format('d.m.Y') }}</span></div>
                                <span class="pill {{ $lead->status === 'new' ? 'a' : 'n' }}">{{ \App\Models\Lead::STATUSES[$lead->status] ?? $lead->status }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($results['companies']?->isNotEmpty())
                <div class="card">
                    <div class="card__head"><h3>Şirketler</h3><span class="sub">{{ $results['companies']->count() }} sonuç</span></div>
                    <div class="rows">
                        @foreach ($results['companies'] as $company)
                            <a href="{{ route('panel.companies.show', $company) }}" class="row">
                                <div class="main-t"><b>{{ $company->legal_name }}</b><span>{{ $company->tax_number ?: '—' }}</span></div>
                                @include('panel.partials.company-status', ['status' => $company->status])
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($results['users']?->isNotEmpty())
                <div class="card">
                    <div class="card__head"><h3>Kullanıcılar</h3><span class="sub">{{ $results['users']->count() }} sonuç</span><span class="r"><a href="{{ route('panel.users.index', ['q' => $q]) }}" class="btn btn--quiet">Tümü</a></span></div>
                    <div class="rows">
                        @foreach ($results['users'] as $u)
                            <a href="{{ route('panel.users.show', $u) }}" class="row">
                                <span class="ap-av" aria-hidden="true">{{ mb_strtoupper(mb_substr($u->name, 0, 1)) }}</span>
                                <div class="main-t"><b>{{ $u->name }}</b><span>{{ $u->email }}</span></div>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
@endsection
