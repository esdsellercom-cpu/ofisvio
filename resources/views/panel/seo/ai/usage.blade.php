@extends('layouts.panel')

@section('title', 'AI kullanımı & maliyet — '.$website->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.ai.index', $website) }}">AI Content Engine</a> · {{ $website->name }}</p>
            <h1 class="h2">API kullanımı & maliyet</h1>
            <p>Her iş kaydının sağlayıcıdan dönen gerçek token sayıları. Maliyet yalnız env'de fiyat tanımlıysa hesaplanır (AI_PRICE_INPUT_PER_MTOK / AI_PRICE_OUTPUT_PER_MTOK, 1M token başına); tanımlı değilse yalnız token gösterilir — tahmini rakam basılmaz.</p>
        </div>
        <div class="panel-head__actions"><a href="{{ route('panel.settings.api') }}" class="btn btn--ghost btn--pill">API & entegrasyonlar</a></div>
    </div>

    <div class="kpis" style="margin-bottom:18px">
        <div class="kpi"><span class="k">İş</span><span class="v">{{ $usage['total']['jobs'] }}</span><span class="d">{{ collect($usage['stages'])->map(fn ($n, $s) => ($stages[$s] ?? $s).' '.$n)->implode(' · ') ?: '—' }}</span></div>
        <div class="kpi"><span class="k">Girdi token</span><span class="v">{{ number_format($usage['total']['input'], 0, ',', '.') }}</span><span class="d">prompt + bağlam</span></div>
        <div class="kpi"><span class="k">Çıktı token</span><span class="v">{{ number_format($usage['total']['output'], 0, ',', '.') }}</span><span class="d">üretilen metin</span></div>
        <div class="kpi"><span class="k">Maliyet</span><span class="v">{{ $usage['priced'] ? number_format($usage['total']['cost'], 4, ',', '.').' '.$usage['currency'] : '—' }}</span><span class="d">{{ $usage['priced'] ? 'env fiyatıyla' : 'fiyat tanımlı değil' }}</span></div>
    </div>

    <div class="panel">
        <p class="eyebrow">Model bazında</p>
        @if ($usage['by_model'] === [])<p class="body-muted" style="margin:0">Henüz AI çağrısı yok.</p>@else
            <table class="data"><thead><tr><th>Model</th><th>İş</th><th>Girdi</th><th>Çıktı</th><th>Maliyet</th></tr></thead><tbody>
                @foreach ($usage['by_model'] as $model => $row)<tr><td class="mono">{{ $model }}</td><td class="mono">{{ $row['jobs'] }}</td><td class="mono">{{ number_format($row['input'], 0, ',', '.') }}</td><td class="mono">{{ number_format($row['output'], 0, ',', '.') }}</td><td class="mono">{{ $usage['priced'] ? number_format($row['cost'], 4, ',', '.').' '.$usage['currency'] : '—' }}</td></tr>@endforeach
            </tbody></table>
        @endif
    </div>
@endsection
