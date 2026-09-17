{{-- Harita: yalnız Google Haritalar gömme (CSP frame-src izinli); adres metni --}}
@php($embed = (string) ($s['embed'] ?? ''))
@if (str_starts_with($embed, 'https://www.google.com/maps/embed') || ofv_editor())
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section--tight sec-inner">
    @if (! empty($s['title']))<h2 class="h2" style="max-width:24ch;margin-bottom:16px"{!! ofv($s, 'title') !!}>{{ $s['title'] }}</h2>@endif
    @if (str_starts_with($embed, 'https://www.google.com/maps/embed'))
        <div class="embed" style="aspect-ratio:16/7;border-radius:var(--sec-radius, var(--r-lg));overflow:hidden"><iframe src="{{ $embed }}" title="Harita" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" style="width:100%;height:100%;border:0"></iframe></div>
    @else
        <div class="shot" style="aspect-ratio:16/7;border:1px dashed var(--line);border-radius:var(--r-lg);display:flex;align-items:center;justify-content:center;color:var(--ink-faint)">Harita gömme adresi girin (Google Haritalar › Paylaş › Harita yerleştir)</div>
    @endif
    @if (! empty($s['address']))<p class="small muted" style="margin:10px 0 0"{!! ofv($s, 'address') !!}>{{ $s['address'] }}</p>@endif
</section>
@endif
