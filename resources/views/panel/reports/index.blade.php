@extends('layouts.panel')

@section('title', 'Raporlar & analitik')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Sistem · raporlar</p>
            <h1 class="h2">Raporlar &amp; analitik</h1>
            <p>Canlı veriden toplamlar; dış analitik bağlı değil. Finans ve franchise sekmeleri ilgili modüllerle birlikte gelir.</p>
        </div>
    </div>

    <div class="stack" style="gap:18px">
        <nav class="tabbar" aria-label="Rapor sekmeleri">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('panel.reports.index', ['sekme' => $key]) }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        @if ($tab === 'gelir')
            @php($b = $data['bookings'])
            <div class="kpis">
                <div class="kpi"><span class="k">Onaylı tutar (30g)</span><span class="v">{{ number_format($b['revenue_30d'], 0, ',', '.') }} ₺</span><span class="d">Onaylı/giriş/tamamlanan rezervasyonlar</span></div>
                <div class="kpi"><span class="k">Ort. süre (30g)</span><span class="v">{{ $b['avg_hours_30d'] }} sa</span><span class="d">Talep başına</span></div>
                <div class="kpi {{ $b['cancel_rate_30d'] > 20 ? 'watch' : '' }}"><span class="k">İptal oranı (30g)</span><span class="v">%{{ $b['cancel_rate_30d'] }}</span><span class="d">{{ $b['no_show_30d'] }} gelmedi</span></div>
                <div class="kpi"><span class="k">Yaklaşan</span><span class="v">{{ $b['upcoming'] }}</span><span class="d">Onaylı, ileri tarihli</span></div>
            </div>
            <div class="card">
                <div class="card__head"><h3>Lokasyona göre rezervasyon</h3><span class="sub">Son 30 gün, adet</span></div>
                <div class="card__body">
                    @if ($b['by_location'] === [])
                        <p class="muted small" style="margin:0">Son 30 günde rezervasyon yok.</p>
                    @else
                        @php($max = max(array_column($b['by_location'], 'count')))
                        @foreach (collect($b['by_location'])->sortByDesc('count') as $loc)
                            <div class="barrow"><span class="lbl" title="{{ $loc['name'] }}">{{ $loc['name'] }}</span><span class="meter"><i style="width:{{ $max > 0 ? round($loc['count'] / $max * 100) : 0 }}%"></i></span><span class="val">{{ $loc['count'] }}</span></div>
                        @endforeach
                    @endif
                </div>
            </div>
            @if ($data['finance'] !== null)
                <div class="card">
                    <div class="card__head"><h3>Tahsilat</h3><span class="sub">Fatura ödemeleri</span></div>
                    <div class="card__body"><div class="stat-s">
                        <div><b>{{ number_format($data['finance']['invoices']['revenue_month'], 0, ',', '.') }} ₺</b><span>Bu ay</span></div>
                        <div><b>{{ number_format($data['finance']['invoices']['revenue_today'], 0, ',', '.') }} ₺</b><span>Bugün</span></div>
                        <div><b>{{ number_format($data['finance']['invoices']['outstanding'], 0, ',', '.') }} ₺</b><span>Bekleyen</span></div>
                        <div><b>{{ number_format($data['finance']['invoices']['overdue'], 0, ',', '.') }} ₺</b><span>Gecikmiş</span></div>
                    </div></div>
                </div>
            @else
                <div class="note">Fatura ve tahsilat toplamları yalnız finans izniyle (invoice.view) görünür.</div>
            @endif

        @elseif ($tab === 'tahsilat')
            @if ($data === [])
                <div class="note w">Tahsilat raporu finans izni (invoice.view) ister.</div>
            @else
                @php($i = $data['invoices'])
                <div class="kpis">
                    <div class="kpi"><span class="k">Bu ay tahsil edilen</span><span class="v">{{ number_format($i['revenue_month'], 0, ',', '.') }} ₺</span><span class="d">Bugün {{ number_format($i['revenue_today'], 0, ',', '.') }} ₺</span></div>
                    <div class="kpi {{ $i['outstanding_count'] > 0 ? 'watch' : '' }}"><span class="k">Bekleyen tahsilat</span><span class="v">{{ number_format($i['outstanding'], 0, ',', '.') }} ₺</span><span class="d">{{ $i['outstanding_count'] }} açık fatura</span></div>
                    <div class="kpi {{ $i['overdue_count'] > 0 ? 'alert' : 'ok' }}"><span class="k">Gecikmiş</span><span class="v">{{ $i['overdue_count'] }}</span><span class="d">{{ number_format($i['overdue'], 0, ',', '.') }} ₺</span></div>
                    <div class="kpi"><span class="k">7 gün içinde vade</span><span class="v">{{ $i['due_7d_count'] }}</span><span class="d">Yayınlanmış fatura</span></div>
                </div>
                <div class="card">
                    <div class="card__head"><h3>Aylık tahsilat</h3><span class="sub">Son 6 ay</span></div>
                    <div class="card__body">
                        @php($max = max(1, max(array_column($data['monthly'], 'amount'))))
                        @foreach ($data['monthly'] as $m)
                            <div class="barrow"><span class="lbl mono">{{ $m['month'] }}</span><span class="meter"><i class="g" style="width:{{ round($m['amount'] / $max * 100) }}%"></i></span><span class="val">{{ number_format($m['amount'], 0, ',', '.') }}</span></div>
                        @endforeach
                    </div>
                </div>
            @endif

        @elseif ($tab === 'doluluk')
            @php($b = $data['bookings'])
            @php($r = $data['rooms'])
            <div class="kpis">
                <div class="kpi"><span class="k">Bugünkü doluluk</span><span class="v">%{{ $b['occupancy_today'] }}</span><span class="d"><span class="meter"><i style="width:{{ min(100, $b['occupancy_today']) }}%"></i></span></span></div>
                <div class="kpi"><span class="k">Bugün</span><span class="v">{{ $b['today'] }}</span><span class="d">Onaylı rezervasyon</span></div>
                <div class="kpi"><span class="k">Alan</span><span class="v">{{ $r['active'] }}</span><span class="d">{{ $r['total'] }} tanımlı</span></div>
                <div class="kpi"><span class="k">Onay bekleyen</span><span class="v">{{ $b['pending'] }}</span><span class="d">Odayı tutan talepler</span></div>
            </div>
            <div class="card">
                <div class="card__head"><h3>Alan türleri</h3><span class="sub">Adet ve kapasite</span></div>
                <div class="tw"><table class="t"><thead><tr><th>Tür</th><th class="num">Alan</th><th class="num">Kapasite</th></tr></thead><tbody>
                    @foreach ($r['by_kind'] as $row)
                        <tr><td><b>{{ $row['label'] }}</b></td><td class="num">{{ $row['count'] }}</td><td class="num">{{ $row['capacity'] }}</td></tr>
                    @endforeach
                </tbody></table></div>
            </div>

        @elseif ($tab === 'uyelik')
            @php($total = array_sum(array_column($data['companies'], 'count')))
            <div class="kpis">
                <div class="kpi"><span class="k">Şirket</span><span class="v">{{ $total }}</span><span class="d">Tüm organizasyonlar</span></div>
                <div class="kpi ok"><span class="k">Aktif</span><span class="v">{{ $data['companies']['ACTIVE']['count'] }}</span><span class="d">Sözleşmesi yürüyen</span></div>
                <div class="kpi"><span class="k">Belge sürecinde</span><span class="v">{{ $data['companies']['REGISTERED']['count'] + $data['companies']['KYC_PENDING']['count'] + $data['companies']['KYC_REVIEW']['count'] }}</span><span class="d">Kayıt / KYC bekliyor / incelemede</span></div>
                @if ($data['kyc_pending'] !== null)
                    <div class="kpi {{ $data['kyc_pending'] > 0 ? 'watch' : '' }}"><span class="k">Bekleyen KYC belgesi</span><span class="v">{{ $data['kyc_pending'] }}</span><span class="d">İnceleme kuyruğu</span></div>
                @endif
            </div>
            <div class="card">
                <div class="card__head"><h3>Şirketler duruma göre</h3></div>
                <div class="card__body">
                    @foreach (array_filter($data['companies'], fn ($row) => $row['count'] > 0) as $row)
                        <div class="barrow"><span class="lbl">{{ $row['label'] }}</span><span class="meter"><i style="width:{{ $total > 0 ? round($row['count'] / $total * 100) : 0 }}%"></i></span><span class="val">{{ $row['count'] }}</span></div>
                    @endforeach
                    @if ($total === 0)<p class="muted small" style="margin:0">Henüz şirket yok.</p>@endif
                </div>
            </div>
            @if ($data['subscriptions'] !== null)
                <div class="card">
                    <div class="card__head"><h3>Üyelikler</h3><span class="sub">Paketlere göre aktif üyelik</span></div>
                    <div class="card__body">
                        <div class="stat-s" style="margin-bottom:8px">
                            <div><b>{{ $data['subscriptions']['active'] }}</b><span>Aktif</span></div>
                            <div><b>{{ $data['subscriptions']['expiring'] }}</b><span>Bitişi 30 gün içinde</span></div>
                            <div><b>{{ $data['subscriptions']['new_30d'] }}</b><span>Yeni (30g)</span></div>
                            <div><b>{{ number_format($data['subscriptions']['mrr'], 0, ',', '.') }} ₺</b><span>MRR</span></div>
                        </div>
                        @php($maxP = max(1, max(array_column($data['subscriptions']['by_plan'], 'count') ?: [0])))
                        @foreach ($data['subscriptions']['by_plan'] as $row)
                            <div class="barrow"><span class="lbl">{{ $row['name'] }}</span><span class="meter"><i style="width:{{ round($row['count'] / $maxP * 100) }}%"></i></span><span class="val">{{ $row['count'] }}</span></div>
                        @endforeach
                    </div>
                </div>
            @endif

        @elseif ($tab === 'talepler')
            @php($total = array_sum(array_column($data['by_status'], 'count')))
            <div class="kpis">
                <div class="kpi"><span class="k">Talep</span><span class="v">{{ $total }}</span><span class="d">{{ $data['by_kind']['quote'] }} teklif · {{ $data['by_kind']['booking'] }} ön rezervasyon</span></div>
                <div class="kpi {{ $data['by_status']['new']['count'] > 0 ? 'watch' : '' }}"><span class="k">Yeni</span><span class="v">{{ $data['by_status']['new']['count'] }}</span><span class="d">Henüz aranmadı</span></div>
                <div class="kpi"><span class="k">Son 30 gün</span><span class="v">{{ $data['last_30d'] }}</span><span class="d">Gelen talep</span></div>
                <div class="kpi ok"><span class="k">Dönüşüm</span><span class="v">%{{ $data['conversion'] }}</span><span class="d">Kazanılan / (kazanılan + kaybedilen)</span></div>
            </div>
            <div class="card">
                <div class="card__head"><h3>Talepler duruma göre</h3></div>
                <div class="card__body">
                    @foreach ($data['by_status'] as $row)
                        <div class="barrow"><span class="lbl">{{ $row['label'] }}</span><span class="meter"><i style="width:{{ $total > 0 ? round($row['count'] / $total * 100) : 0 }}%"></i></span><span class="val">{{ $row['count'] }}</span></div>
                    @endforeach
                </div>
            </div>

        @elseif ($tab === 'bildirim')
            <div class="kpis">
                <div class="kpi"><span class="k">Bugün gönderildi</span><span class="v">{{ $data['counts']['sent_today'] }}</span><span class="d">Tüm kanallar</span></div>
                <div class="kpi {{ $data['counts']['failed'] > 0 ? 'alert' : 'ok' }}"><span class="k">Başarısız</span><span class="v">{{ $data['counts']['failed'] }}</span><span class="d">Denemeler tükendi</span></div>
                <div class="kpi"><span class="k">Kuyrukta</span><span class="v">{{ $data['counts']['queued'] }}</span><span class="d">Gönderim bekliyor</span></div>
            </div>
            <div class="card">
                <div class="card__head"><h3>Kanal sağlığı</h3><span class="sub">Son 30 gün</span></div>
                <div class="tw"><table class="t"><thead><tr><th>Kanal</th><th class="num">Gönderildi</th><th class="num">Başarısız</th><th class="num">Kuyrukta</th></tr></thead><tbody>
                    @foreach ($data['health'] as $channel => $h)
                        <tr><td><b>{{ ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'E-posta', 'in_app' => 'Uygulama içi'][$channel] ?? $channel }}</b></td><td class="num">{{ $h['sent'] }}</td><td class="num">{{ $h['failed'] }}</td><td class="num">{{ $h['queued'] }}</td></tr>
                    @endforeach
                </tbody></table></div>
            </div>

        @elseif ($tab === 'icerik')
            <div class="card">
                <div class="card__head"><h3>İçerik duruma göre</h3><span class="sub">Sayfa ve yazı</span></div>
                <div class="tw"><table class="t"><thead><tr><th>Durum</th><th class="num">Sayfa</th><th class="num">Yazı</th></tr></thead><tbody>
                    @foreach ($data['content'] as $row)
                        <tr><td><b>{{ $row['label'] }}</b></td><td class="num">{{ $row['page'] }}</td><td class="num">{{ $row['post'] }}</td></tr>
                    @endforeach
                </tbody></table></div>
            </div>
        @endif
    </div>
@endsection
