@extends('layouts.panel')

@section('title', 'Genel bakış')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">{{ $activeOrganization->name }}</p>
            <h1 class="h2">Genel bakış</h1>
            <p>{{ $ops !== null ? 'Operasyon özeti tüm lokasyonlar için; şirket listesi aktif organizasyon.' : 'Şirketleriniz, belge süreci ve hızlı işlemler.' }}</p>
        </div>
        <div class="panel-head__actions qa">
            @can('organization.manage')
                <a href="{{ route('panel.companies.create') }}" class="btn btn--brand">Yeni şirket</a>
            @endcan
            @if ($ops !== null)
                @can('booking.view')<a href="{{ route('panel.bookings.index', ['sekme' => 'pending']) }}">Onay bekleyenler</a>@endcan
                @can('lead.view')<a href="{{ route('panel.leads.index', ['status' => 'new']) }}">Yeni talepler</a>@endcan
                @can('geo.edit')<a href="{{ route('panel.geo.create') }}">Yeni lokasyon</a>@endcan
                @canany(['notification.view', 'notification.manage'])<a href="{{ route('panel.notifications.index') }}">Bildirim merkezi</a>@endcanany
                @can('audit.view')<a href="{{ route('panel.audit.index') }}">Denetim kaydı</a>@endcan
            @else
                @foreach ($rows->take(1) as $row)
                    @can('booking.view', $row['company'])<a href="{{ route('panel.companies.bookings.index', $row['company']) }}">Rezervasyonlarım</a>@endcan
                @endforeach
                <a href="{{ route('panel.account') }}">Hesabım</a>
            @endif
        </div>
    </div>

    <div class="stack" style="gap:18px">
        {{-- KPI şeridi: müşteri için şirket sayaçları; personel için operasyon toplamları (gerçek servis verisi) --}}
        @if ($ops === null)
            <div class="kpis" style="grid-template-columns:repeat(3,minmax(0,1fr))">
                <div class="kpi"><span class="k">Şirket</span><span class="v">{{ $counts['total'] }}</span><span class="d">Bu organizasyonda görebildikleriniz</span></div>
                <div class="kpi {{ $counts['in_kyc'] > 0 ? 'watch' : '' }}"><span class="k">Belge sürecinde</span><span class="v">{{ $counts['in_kyc'] }}</span><span class="d">KYC bekleyen şirket</span></div>
                <div class="kpi ok"><span class="k">Aktif</span><span class="v">{{ $counts['active'] }}</span><span class="d">Sözleşmesi yürüyen</span></div>
            </div>
        @else
            @php($b = $ops['bookings'])
            <div class="kpis kpis--6">
                @if ($b !== null)
                    <a href="{{ route('panel.bookings.index', ['sekme' => 'today']) }}" class="kpi"><span class="k">Bugünkü rezervasyon</span><span class="v">{{ $b['today'] }}</span><span class="d">{{ $b['rooms'] }} aktif oda</span></a>
                    <a href="{{ route('panel.bookings.index', ['sekme' => 'pending']) }}" class="kpi {{ $b['pending'] > 0 ? 'alert' : 'ok' }}"><span class="k">Onay bekleyen</span><span class="v">{{ $b['pending'] }}</span><span class="d">{{ $b['pending'] > 0 ? 'İşlem gerekiyor' : 'Kuyruk boş' }}</span></a>
                    <div class="kpi"><span class="k">Bugünkü doluluk</span><span class="v">%{{ $b['occupancy_today'] }}</span><span class="d"><span class="meter"><i class="{{ $b['occupancy_today'] >= 80 ? 'g' : ($b['occupancy_today'] >= 40 ? '' : 'w') }}" style="width:{{ min(100, $b['occupancy_today']) }}%"></i></span></span></div>
                    <a href="{{ route('panel.bookings.index', ['sekme' => 'upcoming']) }}" class="kpi"><span class="k">Yaklaşan</span><span class="v">{{ $b['upcoming'] }}</span><span class="d">Onaylı, ileri tarihli</span></a>
                    <div class="kpi"><span class="k">Onaylı tutar (30g)</span><span class="v">{{ number_format($b['revenue_30d'], 0, ',', '.') }} ₺</span><span class="d">Ort. {{ $b['avg_hours_30d'] }} sa</span></div>
                    <div class="kpi {{ $b['cancel_rate_30d'] > 20 ? 'watch' : '' }}"><span class="k">İptal oranı (30g)</span><span class="v">%{{ $b['cancel_rate_30d'] }}</span><span class="d">{{ $b['no_show_30d'] }} gelmedi</span></div>
                @endif
                @if ($ops['leads'] !== null)
                    <a href="{{ route('panel.leads.index', ['status' => 'new']) }}" class="kpi {{ $ops['leads']->total() > 0 ? 'watch' : '' }}"><span class="k">Yeni talep</span><span class="v">{{ $ops['leads']->total() }}</span><span class="d">Teklif ve ön rezervasyon</span></a>
                @endif
                @if ($ops['kyc_pending'] !== null)
                    <a href="{{ route('panel.kyc.queue') }}" class="kpi {{ $ops['kyc_pending'] > 0 ? 'watch' : '' }}"><span class="k">Bekleyen KYC</span><span class="v">{{ $ops['kyc_pending'] }}</span><span class="d">Tüm organizasyonlar</span></a>
                @endif
                @if ($ops['notifications'] !== null)
                    <a href="{{ route('panel.notifications.index', ['sekme' => 'gunluk']) }}" class="kpi {{ $ops['notifications']['failed'] > 0 ? 'alert' : '' }}"><span class="k">Başarısız bildirim</span><span class="v">{{ $ops['notifications']['failed'] }}</span><span class="d">{{ $ops['notifications']['queued'] }} kuyrukta · {{ $ops['notifications']['sent_today'] }} bugün gönderildi</span></a>
                @endif
                @if ($ops['locations'] !== null)
                    <a href="{{ route('panel.geo.index') }}" class="kpi"><span class="k">Yayında lokasyon</span><span class="v">{{ $ops['locations']['published'] }}</span><span class="d">{{ $ops['locations']['total'] }} kayıtlı şube</span></a>
                @endif
                <div class="kpi"><span class="k">Şirket (bu organizasyon)</span><span class="v">{{ $counts['total'] }}</span><span class="d">{{ $counts['active'] }} aktif · {{ $counts['in_kyc'] }} belge sürecinde</span></div>
            </div>

            @if ($b !== null)
                <div class="grid g-2-1">
                    <div class="card">
                        <div class="card__head"><h3>Bugünkü rezervasyonlar</h3><span class="sub">{{ now()->format('d.m.Y') }}</span><span class="r"><a href="{{ route('panel.bookings.index', ['sekme' => 'today']) }}" class="btn btn--quiet">Tümü</a></span></div>
                        @if ($ops['today']->isEmpty())
                            <div class="empty-state" style="border:0">Bugün için onaylı rezervasyon yok.</div>
                        @else
                            <div class="tw">
                                <table class="t">
                                    <thead><tr><th>Saat</th><th>Müşteri</th><th>Oda</th><th>Durum</th></tr></thead>
                                    <tbody>
                                        @foreach ($ops['today'] as $row)
                                            <tr>
                                                <td class="num">{{ $row->starts_at->format('H:i') }}–{{ $row->ends_at->format('H:i') }}</td>
                                                <td><div class="who2"><div><b><a href="{{ route('panel.bookings.show', [$row->location, $row]) }}">{{ $row->customer_name }}</a></b><small>{{ $row->company?->legal_name ?? $row->company_name ?? $row->reference }}</small></div></div></td>
                                                <td>{{ $row->room->name }}<br><span class="mini">{{ $row->location->name }}</span></td>
                                                <td><span class="pill {{ ['ok' => 'g', 'warn' => 'w', 'muted' => 'n'][$row->status->badge()] }}">{{ $row->status->label() }}</span></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    <div class="card">
                        <div class="card__head"><h3>Lokasyon performansı</h3><span class="sub">Son 30 gün, rezervasyon adedi</span></div>
                        <div class="card__body">
                            @if ($b['by_location'] === [])
                                <p class="muted small" style="margin:0">Son 30 günde rezervasyon yok.</p>
                            @else
                                @php($max = max(array_column($b['by_location'], 'count')))
                                @foreach (collect($b['by_location'])->sortByDesc('count') as $loc)
                                    <div class="barrow">
                                        <span class="lbl" title="{{ $loc['name'] }}">{{ $loc['name'] }}</span>
                                        <span class="meter"><i style="width:{{ $max > 0 ? round($loc['count'] / $max * 100) : 0 }}%"></i></span>
                                        <span class="val">{{ $loc['count'] }}</span>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            @if ($b !== null || $ops['leads'] !== null)
                <div class="grid g2">
                    @if ($b !== null)
                        <div class="card">
                            <div class="card__head"><h3>Onay bekleyenler</h3><span class="sub">En yakın tarih önce</span><span class="r"><a href="{{ route('panel.bookings.index', ['sekme' => 'pending']) }}" class="btn btn--quiet">Tümü</a></span></div>
                            @if ($ops['pending']->isEmpty())
                                <div class="empty-state" style="border:0">Onay bekleyen rezervasyon yok.</div>
                            @else
                                <div class="rows">
                                    @foreach ($ops['pending'] as $row)
                                        <a href="{{ route('panel.bookings.show', [$row->location, $row]) }}" class="row">
                                            <span class="dotmark" style="background:var(--warn)" aria-hidden="true"></span>
                                            <div class="main-t"><b>{{ $row->customer_name }} · {{ $row->room->name }}</b><span>{{ $row->location->name }} · {{ $row->starts_at->format('d.m.Y H:i') }} · {{ $row->reference }}</span></div>
                                            <span class="rt mono">{{ number_format($row->total_amount, 0, ',', '.') }} ₺</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($ops['leads'] !== null)
                        <div class="card">
                            <div class="card__head"><h3>Yeni talepler</h3><span class="sub">Siteden gelen teklif / ön rezervasyon</span><span class="r"><a href="{{ route('panel.leads.index') }}" class="btn btn--quiet">Tümü</a></span></div>
                            @if ($ops['leads']->isEmpty())
                                <div class="empty-state" style="border:0">Bekleyen yeni talep yok.</div>
                            @else
                                <div class="rows">
                                    @foreach ($ops['leads'] as $lead)
                                        <a href="{{ route('panel.leads.show', $lead) }}" class="row">
                                            <span class="ap-av" aria-hidden="true">{{ mb_strtoupper(mb_substr($lead->name, 0, 1)) }}</span>
                                            <div class="main-t"><b>{{ $lead->name }}</b><span>{{ $lead->kind === 'booking' ? 'Ön rezervasyon' : 'Teklif' }}@if ($lead->solution) · {{ $lead->solution }}@endif @if ($lead->location) · {{ $lead->location->name }}@endif</span></div>
                                            <span class="rt mini">{{ $lead->created_at->diffForHumans(null, true) }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        @endif

        {{-- Şirketler (aktif organizasyon) --}}
        <div class="card">
            <div class="card__head"><h3>Şirketler</h3><span class="sub">{{ $activeOrganization->name }} · {{ $counts['total'] }} şirket</span><span class="r"><a href="{{ route('panel.companies.index') }}" class="btn btn--quiet">Tümü</a></span></div>
            @if ($rows->isEmpty())
                <div class="empty-state" style="border:0">
                    Bu organizasyonda görebildiğiniz şirket yok.
                    @can('organization.manage')
                        <a href="{{ route('panel.companies.create') }}" style="color:var(--accent-2);font-weight:600">İlk şirketi açın.</a>
                    @endcan
                </div>
            @else
                <div class="tw">
                    <table class="t">
                        <thead>
                            <tr>
                                <th>Şirket</th>
                                <th>Durum</th>
                                <th>KYC belgeleri</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td><b><a href="{{ route('panel.companies.show', $row['company']) }}">{{ $row['company']->legal_name }}</a></b></td>
                                    <td>@include('panel.partials.company-status', ['status' => $row['company']->status])</td>
                                    <td>
                                        @if ($row['kyc']['complete'])
                                            <span class="pill g">Tamam</span>
                                        @else
                                            <span class="pill w">{{ count($row['kyc']['missing']) }} zorunlu belge eksik</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            @canany(['kyc.view', 'kyc.view_status'], $row['company'])
                                                <a href="{{ route('panel.companies.kyc.show', $row['company']) }}" class="btn btn--ghost btn--pill">Belgeler</a>
                                            @endcanany
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @can('organization.manage')
            <div class="card" style="max-width:520px">
                <div class="card__head"><h3>Organizasyon künyesi</h3></div>
                <div class="card__body">
                    <form method="POST" action="{{ route('panel.organization.update') }}" class="stack" style="gap:10px">
                        @csrf @method('PUT')
                        <label class="field"><span class="label">Organizasyon adı</span>
                            <input class="control" type="text" name="name" value="{{ old('name', $activeOrganization->name) }}" required minlength="2" maxlength="120" @error('name') aria-invalid="true" @enderror>
                        </label>
                        <div><button type="submit" class="btn btn--ghost">Kaydet</button></div>
                    </form>
                </div>
            </div>
        @endcan
    </div>
@endsection
