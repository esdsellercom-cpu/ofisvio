{{-- SEO Engine (faz 15): SeoService::head() çıktısı. $seo yoksa (kompozer dışı render) hiçbir şey basılmaz. --}}
@isset($seo)
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['robots'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">
    <meta property="og:type" content="{{ $seo['og_type'] }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    @if (! empty($seo['og_image']))<meta property="og:image" content="{{ $seo['og_image'] }}">@endif
    <meta property="og:locale" content="{{ $seo['locale'] }}">
    <meta property="og:site_name" content="{{ $currentWebsite->name ?? config('ofisvio.brand.name') }}">
    <meta name="twitter:card" content="summary">
    <script type="application/ld+json">{!! json_encode($seo['json_ld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endisset
