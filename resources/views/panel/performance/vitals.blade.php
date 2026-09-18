@extends('layouts.panel')

@section('title', 'Core Web Vitals')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Performance Command Center</p>
            <h1 class="h2">Core Web Vitals</h1>
            <p>PageSpeed Insights ile temsilî sayfalar (ana sayfa + her türden ilk sayfa) mobil/masaüstü ölçülür — lab (Lighthouse) ve varsa alan (CrUX p75) verisi. Tarayıcıdan ölçüm gönderilmez; ölçüm sunucudan Gateway üzerinden. Eşikler: LCP ≤ 2,5 s · INP ≤ 200 ms · CLS ≤ 0,1.</p>
        </div>
        <div class="panel-head__actions">
            <span class="badge badge--{{ $enabled ? 'ok' : 'muted' }}">PageSpeed: {{ $enabled ? 'açık' : 'kapalı (PAGESPEED_ENABLED)' }}</span>
            @if ($canMeasure)<form method="POST" action="{{ route('panel.performance.vitals.measure') }}">@csrf<button type="submit" class="btn btn--brand btn--pill" @disabled(! $enabled)>Şimdi ölç ({{ count($paths) }} sayfa × 2)</button></form>@endif
        </div>
    </div>
    @include('panel.performance.partials.nav')
    @if ($state !== null && $state->last_error)<div class="note c" style="margin-bottom:14px">Son hata: {{ $state->last_error }}</div>@endif
    @if ($rows->isEmpty())
        <div class="panel"><p class="body-muted" style="margin:0">Ölçüm yok. Sağlayıcı açıksa "Şimdi ölç" ya da <span class="mono">php artisan ofisvio:web-vitals</span> (zamanlayıcı haftalık). Ölçülecek sayfalar: {{ implode(', ', $paths) }}.</p></div>
    @else
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Sayfa</th><th>Cihaz</th><th>Skor</th><th>LCP (ms)</th><th>INP (ms)</th><th>CLS</th><th>FCP (ms)</th><th>TTFB (ms)</th><th>Ölçüm</th></tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    @php($s = $row['sample'])
                    <tr><td class="mono small">{{ $s->path }}</td><td class="small">{{ $s->strategy }}</td><td class="mono">{{ $s->score ?? '—' }}</td>
                        @foreach (['lcp_ms', 'inp_ms', 'cls', 'fcp_ms', 'ttfb_ms'] as $m)
                            @php($value = $s->field[$m] ?? $s->lab[$m] ?? null)
                            <td><span class="badge badge--{{ ['good' => 'ok', 'needs' => 'warn', 'poor' => 'danger', 'none' => 'muted'][$row['grades'][$m]] }}">{{ $value === null ? '—' : round($value, $m === 'cls' ? 3 : 0) }}{{ isset($s->field[$m]) ? ' (alan)' : '' }}</span></td>
                        @endforeach
                        <td class="small">{{ $s->measured_at->format('d.m.Y H:i') }}</td></tr>
                @endforeach
            </tbody>
        </table></div>
    @endif
@endsection
