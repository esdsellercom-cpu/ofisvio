@extends('layouts.panel')

@section('title', 'İç bağlantı motoru — '.$website->name)

@section('content')
    @php($c = $graph['counts'])
    @php($kinds = ['service' => 'Hizmet', 'location' => 'Lokasyon', 'post' => 'Yazı', 'page' => 'Sayfa', 'landing' => 'Hizmet × şehir'])
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.center', $website) }}">Command Center</a> · {{ $website->name }}</p>
            <h1 class="h2">Internal Linking Engine</h1>
            <p>Graf gerçek kaynaklardan kurulur: içerik gövdelerindeki bağlantılar, otomatik anahtar kelime kuralları, menü, hizmet ↔ lokasyon ↔ hizmet × şehir yapısal bağlantıları ve Knowledge Graph ilişkileri. Öneriler ilgililik puanlıdır (varlık ilişkisi 5 · metinde geçen bağlanmamış ad 4 · aynı konu kümesi 3 · ortak etiket 2 · aynı kategori 2). Manuel bağlantı = anahtar kelime → adres kuralı.</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.seo.settings.show', [$website, 'baglanti']) }}" class="btn btn--ghost btn--pill">Bağlantı ayarları (sınırlar, breadcrumb)</a>
        </div>
    </div>

    <div class="kpis kpis--6" style="margin-bottom:18px">
        <div class="kpi"><span class="k">Sayfa</span><span class="v">{{ $c['pages'] }}</span><span class="d">düğüm</span></div>
        <div class="kpi"><span class="k">Bağlantı</span><span class="v">{{ $c['edges'] }}</span><span class="d">benzersiz kenar</span></div>
        <div class="kpi {{ $c['orphans'] > 0 ? 'alert' : 'ok' }}"><span class="k">Yetim sayfa</span><span class="v">{{ $c['orphans'] }}</span><span class="d">gelen bağlantı yok</span></div>
        <div class="kpi {{ $c['broken'] > 0 ? 'alert' : 'ok' }}"><span class="k">Kırık iç bağlantı</span><span class="v">{{ $c['broken'] }}</span><span class="d">gövdede var olmayan adres</span></div>
        <div class="kpi"><span class="k">Ort. yoğunluk</span><span class="v">{{ $c['avg_density'] ?? '—' }}</span><span class="d">bağlantı / 100 kelime</span></div>
        <div class="kpi"><span class="k">Otomatik kural</span><span class="v">{{ count($graph['rules']) }}</span><span class="d">{{ $graph['auto_enabled'] ? 'açık' : 'kapalı' }}</span></div>
    </div>

    <div class="grid-auto" style="--min:320px;--gap:18px;align-items:start;margin-bottom:18px">
        <div class="panel">
            <p class="eyebrow">Hizmet ↔ Lokasyon ↔ Blog matrisi (kaynak → hedef)</p>
            <table class="data">
                <thead><tr><th></th>@foreach ($kinds as $label)<th class="small">{{ $label }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($kinds as $from => $label)
                        <tr><th class="small">{{ $label }}</th>@foreach ($kinds as $to => $l)<td class="mono {{ ($graph['matrix'][$from][$to] ?? 0) === 0 ? 'muted' : '' }}">{{ $graph['matrix'][$from][$to] ?? 0 }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="panel">
            <p class="eyebrow">Manuel bağlantı kuralları (anahtar kelime → adres)</p>
            @if ($canEdit)
                <form method="POST" action="{{ route('panel.seo.links.rule', $website) }}" class="inline-form" style="margin-bottom:10px;flex-wrap:wrap">
                    @csrf
                    <input class="control" type="text" name="keyword" placeholder="çapa metni" required minlength="2" maxlength="80" style="flex:1 1 140px">
                    <input class="control mono" type="text" name="url" placeholder="/cozum/sanal-ofis" required maxlength="300" style="flex:1 1 180px">
                    <button type="submit" class="btn btn--brand btn--pill">Ekle</button>
                </form>
            @endif
            @if ($graph['rules'] === [])<p class="body-muted small" style="margin:0">Kural yok.</p>@else
                <ul style="margin:0;padding-left:18px">
                    @foreach ($graph['rules'] as $rule)
                        <li><strong>{{ $rule['keyword'] }}</strong> → <span class="mono small">{{ $rule['to'] }}</span>
                            @if ($canEdit)<form method="POST" action="{{ route('panel.seo.links.rule.destroy', $website) }}" style="display:inline">@csrf @method('DELETE')<input type="hidden" name="keyword" value="{{ $rule['keyword'] }}"><button type="submit" class="btn btn--ghost" style="padding:0 6px;border:0" title="Kaldır">×</button></form>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="panel" style="margin-bottom:18px">
        <p class="eyebrow">Bağlantı önerileri ({{ count($graph['suggestions']) }})</p>
        @if ($graph['suggestions'] === [])<p class="body-muted" style="margin:0">Öneri yok — tüm ilgili hedefler bağlı ya da gerekçe yok. Yazıları Knowledge Graph'ta hizmet/lokasyona bağlayın, anahtar kelimelere küme verin.</p>@else
            <table class="data">
                <thead><tr><th>Kaynak</th><th>Hedef</th><th>Puan</th><th>Gerekçe</th><th>Çapa metni</th></tr></thead>
                <tbody>
                    @foreach (array_slice($graph['suggestions'], 0, 60) as $s)
                        <tr>
                            <td class="small"><strong>{{ $s['from']['label'] }}</strong><span class="mono muted" style="display:block">{{ $s['from']['path'] }}</span></td>
                            <td class="small">{{ $s['to']['label'] }} <span class="badge badge--info">{{ $kinds[$s['to']['kind']] ?? $s['to']['kind'] }}</span><span class="mono muted" style="display:block">{{ $s['to']['path'] }}</span></td>
                            <td class="mono">{{ $s['score'] }}</td>
                            <td class="small muted">{{ implode(' · ', $s['reasons']) }}</td>
                            <td class="small mono">[{{ $s['anchor'] }}]({{ $s['to']['path'] }})</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="panel">
        <p class="eyebrow">Sayfalar — sırala:
            @foreach (['in' => 'gelen ↑', 'out' => 'giden ↑', 'density' => 'yoğunluk ↑', 'broken' => 'kırık ↓'] as $key => $label)<a href="{{ route('panel.seo.links', [$website, 'sirala' => $key]) }}" @if ($sort === $key) style="font-weight:700" @endif>{{ $label }}</a>@if (! $loop->last) · @endif @endforeach
        </p>
        <table class="data">
            <thead><tr><th>Sayfa</th><th>Tür</th><th>Gelen</th><th>Giden</th><th>Kelime</th><th>Yoğunluk</th><th>Kırık</th></tr></thead>
            <tbody>
                @foreach ($nodes as $n)
                    <tr class="{{ $n['in'] === 0 && $n['path'] !== '/' ? 'is-warn' : '' }}">
                        <td class="small"><strong>{{ $n['label'] }}</strong><span class="mono muted" style="display:block">{{ $n['path'] }}</span></td>
                        <td class="small">{{ $kinds[$n['kind']] ?? $n['kind'] }}</td>
                        <td class="mono">{{ $n['in'] }}@if ($n['in'] === 0 && ! in_array($n['kind'], ['static', 'listing', 'home'], true)) <span class="badge badge--danger">yetim</span>@endif</td>
                        <td class="mono">{{ $n['out'] }}</td>
                        <td class="mono">{{ $n['words'] ?? '—' }}</td>
                        <td class="mono">{{ $n['density'] ?? '—' }}</td>
                        <td class="mono">{{ $n['broken'] > 0 ? $n['broken'] : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
