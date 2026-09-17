{{-- Operasyon özeti (audit P1-13): tenant bağlamı olmadan, /panel/operasyon. $ops: OperationsDashboardController. --}}
        @php($b = $ops['bookings'])
        <div class="kpis kpis--6">
            @if ($b !== null)
                <a href="{{ route('panel.bookings.index', ['sekme' => 'today']) }}" class="kpi"><span class="k">Bugünkü rezervasyon</span><span class="v">{{ $b['today'] }}</span><span class="d">{{ $b['rooms'] }} aktif oda</span></a>
                <a href="{{ route('panel.bookings.index', ['sekme' => 'pending']) }}" class="kpi {{ $b['pending'] > 0 ? 'alert' : 'ok' }}"><span class="k">Onay bekleyen</span><span class="v">{{ $b['pending'] }}</span><span class="d">{{ $b['pending'] > 0 ? 'İşlem gerekiyor' : 'Kuyruk boş' }}</span></a>
                <div class="kpi"><span class="k">Bugünkü doluluk</span><span class="v">%{{ $b['occupancy_today'] }}</span><span class="d"><span class="meter"><i class="{{ $b['occupancy_today'] >= 80 ? 'g' : ($b['occupancy_today'] >= 40 ? '' : 'w') }}" style="width:{{ min(100, $b['occupancy_today']) }}%"></i></span></span></div>
                <a href="{{ route('panel.bookings.index', ['sekme' => 'upcoming']) }}" class="kpi"><span class="k">Yaklaşan</span><span class="v">{{ $b['upcoming'] }}</span><span class="d">Onaylı, ileri tarihli</span></a>
                <div class="kpi"><span class="k">Onaylı tutar (30g)</span><span class="v">{{ money($b['revenue_30d']) }}</span><span class="d">Ort. {{ $b['avg_hours_30d'] }} sa</span></div>
                <div class="kpi {{ $b['cancel_rate_30d'] > 20 ? 'watch' : '' }}"><span class="k">İptal oranı (30g)</span><span class="v">%{{ $b['cancel_rate_30d'] }}</span><span class="d">{{ $b['no_show_30d'] }} gelmedi</span></div>
            @endif
            @if ($ops['spaces'] !== null && $ops['spaces']['total'] > 0)
                <a href="{{ route('panel.spaces.index') }}" class="kpi {{ $ops['spaces']['rate'] >= 90 ? 'ok' : ($ops['spaces']['rate'] < 50 ? 'watch' : '') }}"><span class="k">Doluluk (masa/ofis)</span><span class="v">%{{ $ops['spaces']['rate'] }}</span><span class="d">{{ $ops['spaces']['occupied'] }} / {{ $ops['spaces']['slots'] }} yer · {{ $ops['spaces']['ending_30d'] }} tahsis 30 günde bitiyor</span></a>
            @endif
            @if ($ops['leads'] !== null)
                <a href="{{ route('panel.leads.index', ['status' => 'new']) }}" class="kpi {{ $ops['leads']->total() > 0 ? 'watch' : '' }}"><span class="k">Yeni talep</span><span class="v">{{ $ops['leads']->total() }}</span><span class="d">Teklif ve ön rezervasyon</span></a>
            @endif
            @if ($ops['kyc_pending'] !== null)
                <a href="{{ route('panel.kyc.queue') }}" class="kpi {{ $ops['kyc_pending'] > 0 ? 'watch' : '' }}"><span class="k">Bekleyen KYC</span><span class="v">{{ $ops['kyc_pending'] }}</span><span class="d">Tüm organizasyonlar</span></a>
            @endif
            @if ($ops['finance'] !== null)
                <div class="kpi"><span class="k">Günlük ciro</span><span class="v">{{ money($ops['finance']['revenue_today']) }}</span><span class="d">Bugün kaydedilen tahsilat</span></div>
                <a href="{{ route('panel.collections.index') }}" class="kpi"><span class="k">Aylık ciro</span><span class="v">{{ money($ops['finance']['revenue_month']) }}</span><span class="d">Bu ay tahsil edilen</span></a>
                <a href="{{ route('panel.invoices.index', ['sekme' => 'open']) }}" class="kpi {{ $ops['finance']['outstanding_count'] > 0 ? 'watch' : '' }}"><span class="k">Bekleyen tahsilat</span><span class="v">{{ money($ops['finance']['outstanding']) }}</span><span class="d">{{ $ops['finance']['outstanding_count'] }} açık fatura</span></a>
                <a href="{{ route('panel.invoices.index', ['sekme' => 'overdue']) }}" class="kpi {{ $ops['finance']['overdue_count'] > 0 ? 'alert' : 'ok' }}"><span class="k">Gecikmiş ödeme</span><span class="v">{{ $ops['finance']['overdue_count'] }}</span><span class="d">{{ money($ops['finance']['overdue']) }} vadesi geçmiş</span></a>
            @endif
            @if ($ops['subscriptions'] !== null)
                <a href="{{ route('panel.subscriptions.index', ['sekme' => 'expiring']) }}" class="kpi {{ $ops['subscriptions']['expiring'] > 0 ? 'watch' : '' }}"><span class="k">Üyelik bitişi (30 gün)</span><span class="v">{{ $ops['subscriptions']['expiring'] }}</span><span class="d">{{ $ops['subscriptions']['active'] }} aktif üyelik</span></a>
                <a href="{{ route('panel.subscriptions.index') }}" class="kpi"><span class="k">Yeni üyelik (30g)</span><span class="v">{{ $ops['subscriptions']['new_30d'] }}</span><span class="d">MRR {{ money($ops['subscriptions']['mrr']) }}</span></a>
            @endif
            @if ($ops['events'] !== null)
                <a href="{{ route('panel.events.index') }}" class="kpi"><span class="k">Yaklaşan etkinlik</span><span class="v">{{ $ops['events']['upcoming'] }}</span><span class="d">{{ $ops['events']['next'] ? $ops['events']['next']->title.' · '.$ops['events']['next']->starts_at->format('d.m H:i') : $ops['events']['registrations_30d'].' kayıt (30g)' }}</span></a>
            @endif
            @if ($ops['franchise'] !== null)
                <a href="{{ route('panel.franchise.index', ['status' => 'new']) }}" class="kpi {{ $ops['franchise']['new'] > 0 ? 'watch' : '' }}"><span class="k">Franchise başvurusu</span><span class="v">{{ $ops['franchise']['new'] }}</span><span class="d">{{ $ops['franchise']['reviewing'] }} değerlendirmede · {{ $ops['franchise']['approved'] }} onaylı</span></a>
            @endif
            @if ($ops['notifications'] !== null)
                <a href="{{ route('panel.notifications.index', ['sekme' => 'gunluk']) }}" class="kpi {{ $ops['notifications']['failed'] > 0 ? 'alert' : '' }}"><span class="k">Başarısız bildirim</span><span class="v">{{ $ops['notifications']['failed'] }}</span><span class="d">{{ $ops['notifications']['queued'] }} kuyrukta · {{ $ops['notifications']['sent_today'] }} bugün gönderildi</span></a>
            @endif
            @if ($ops['locations'] !== null)
                <a href="{{ route('panel.geo.index') }}" class="kpi"><span class="k">Yayında lokasyon</span><span class="v">{{ $ops['locations']['published'] }}</span><span class="d">{{ $ops['locations']['total'] }} kayıtlı şube</span></a>
            @endif
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
                                        <span class="rt mono">{{ money($row->total_amount) }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                @if ($ops['spaces'] !== null && $ops['spaces']['total'] > 0)
                <a href="{{ route('panel.spaces.index') }}" class="kpi {{ $ops['spaces']['rate'] >= 90 ? 'ok' : ($ops['spaces']['rate'] < 50 ? 'watch' : '') }}"><span class="k">Doluluk (masa/ofis)</span><span class="v">%{{ $ops['spaces']['rate'] }}</span><span class="d">{{ $ops['spaces']['occupied'] }} / {{ $ops['spaces']['slots'] }} yer · {{ $ops['spaces']['ending_30d'] }} tahsis 30 günde bitiyor</span></a>
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

        @if ($ops !== null && $ops['overdue'] !== null && $ops['overdue']->isNotEmpty())
            <div class="card">
                <div class="card__head"><h3>Gecikmiş ödemeler</h3><span class="sub">Vadesi geçmiş açık faturalar</span><span class="r"><a href="{{ route('panel.invoices.index', ['sekme' => 'overdue']) }}" class="btn btn--quiet">Tümü</a></span></div>
                <div class="tw">
                    <table class="t">
                        <thead><tr><th>Fatura</th><th>Şirket</th><th>Vade</th><th class="num">Kalan</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($ops['overdue'] as $inv)
                                <tr>
                                    <td class="mono">{{ $inv->number }}</td>
                                    <td><b>{{ $inv->company->legal_name }}</b><br><span class="mini">{{ $inv->description }}</span></td>
                                    <td><span class="pill c flat">{{ $inv->daysOverdue() }} gün gecikti</span></td>
                                    <td class="num">{{ money($inv->outstanding()) }}</td>
                                    <td class="num"><a href="{{ route('panel.invoices.show', $inv) }}" class="btn btn--quiet">Aç</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($ops !== null && $ops['expiring'] !== null && $ops['expiring']->isNotEmpty())
            <div class="card">
                <div class="card__head"><h3>Yaklaşan üyelik bitişleri</h3><span class="sub">30 gün içinde</span><span class="r"><a href="{{ route('panel.subscriptions.index', ['sekme' => 'expiring']) }}" class="btn btn--quiet">Tümü</a></span></div>
                <div class="rows">
                    @foreach ($ops['expiring'] as $s)
                        <a href="{{ route('panel.subscriptions.show', $s) }}" class="row">
                            <span class="dotmark" style="background:var(--warn)" aria-hidden="true"></span>
                            <div class="main-t"><b>{{ $s->company->legal_name }} · {{ $s->plan->name }}</b><span>Bitiş {{ $s->ends_on->format('d.m.Y') }} · {{ $s->auto_renew ? 'yenilenecek' : 'yenilenmeyecek' }}</span></div>
                            <span class="rt"><span class="pill w flat">{{ $s->daysLeft() }} gün</span></span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

