{{-- Serbest metin bölümü: başlık + Markdown gövde (güvenli HTML) + CTA --}}
@php($cta = app(\App\Services\SiteBuilderService::class)->cta($s['cta'] ?? null, $currentWebsite))
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section">
    @if (! empty($s['eyebrow']))<p class="eyebrow"{!! ofv($s, 'eyebrow') !!}>{{ $s['eyebrow'] }}</p>@endif
    @if (! empty($s['title']))<h2 class="h2" style="max-width:26ch"{!! ofv($s, 'title') !!}>{{ $s['title'] }}</h2>@endif
    @if (! empty($s['body']))<div class="prose" style="margin-top:22px;max-width:70ch"{!! ofv_editor() ? ' data-ofv-md="body"' : '' !!}>{!! \Illuminate\Support\Str::markdown((string) $s['body'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>@endif
    @if ($cta)<p style="margin-top:24px"><a href="{{ $cta['href'] }}" class="btn btn--brand" @if ($cta['external']) target="_blank" rel="noopener" @endif>{{ $cta['label'] }}</a></p>@endif
</section>