{{-- Düğmeler: bir–üç CTA yan yana --}}
@php($svc = app(\App\Services\SiteBuilderService::class))
@php($ctas = array_values(array_filter([$svc->cta($s['cta'] ?? null, $currentWebsite), $svc->cta($s['cta2'] ?? null, $currentWebsite), $svc->cta($s['cta3'] ?? null, $currentWebsite)])))
@php($class = match ($s['variant'] ?? 'brand') { 'ghost' => 'btn btn--ghost btn--pill', 'link' => 'btn btn--link', default => 'btn btn--brand btn--pill' })
@if ($ctas !== [] || ofv_editor())
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section--tight sec-inner" style="display:flex;flex-wrap:wrap;gap:12px;justify-content:var(--sec-justify, flex-start)">
    @forelse ($ctas as $cta)
        <a href="{{ $cta['href'] }}" class="{{ $class }}" @if ($cta['external']) target="_blank" rel="noopener" @endif>{{ $cta['label'] }}</a>
    @empty
        <span class="muted small">Düğme ayarlanmadı (sağ panel › CTA).</span>
    @endforelse
</section>
@endif
