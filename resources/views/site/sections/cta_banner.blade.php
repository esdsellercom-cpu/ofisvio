{{-- CTA şeridi: mesaj + eylem (site ayarı olmayan telefon/WhatsApp → düğme basılmaz) --}}
@php($cta = app(\App\Services\SiteBuilderService::class)->cta($s['cta'] ?? null, $currentWebsite))
@if (! empty($s['title']))
<section @if ($anchor) id="{{ $anchor }}" @endif class="{{ ($s['style'] ?? 'dark') === 'dark' ? 'dark-band' : 'wrap section' }}" @if (($s['style'] ?? 'dark') === 'dark') style="margin-top:72px" @endif>
    <div class="{{ ($s['style'] ?? 'dark') === 'dark' ? 'wrap' : 'card' }}" style="padding:{{ ($s['style'] ?? 'dark') === 'dark' ? '56px 0' : '32px' }};display:flex;flex-wrap:wrap;gap:20px;align-items:center;justify-content:space-between">
        <div style="min-width:0">
            <h2 class="h2" style="font-size:clamp(24px,3vw,34px);max-width:28ch">{{ $s['title'] }}</h2>
            @if (! empty($s['lede']))<p class="lede" style="margin:10px 0 0;font-size:16.5px">{{ $s['lede'] }}</p>@endif
        </div>
        @if ($cta)<a href="{{ $cta['href'] }}" class="btn btn--brand btn--pill" @if ($cta['external']) target="_blank" rel="noopener" @endif>{{ $cta['label'] }}</a>@endif
    </div>
</section>
@endif