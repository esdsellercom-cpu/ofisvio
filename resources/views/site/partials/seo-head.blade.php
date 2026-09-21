{{-- SEO Engine (faz 15 + faz 44): SeoService::head() çıktısı. $seo yoksa (kompozer dışı render) hiçbir şey basılmaz. --}}
@isset($seo)
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    @if (($seo['keywords'] ?? '') !== '')<meta name="keywords" content="{{ $seo['keywords'] }}">@endif
    <meta name="robots" content="{{ $seo['robots'] }}">
    @if (! empty($seo['canonical']))<link rel="canonical" href="{{ $seo['canonical'] }}">@endif
    @foreach ($seo['hreflang'] ?? [] as $alt)<link rel="alternate" hreflang="{{ $alt['hreflang'] }}" href="{{ $alt['href'] }}">@endforeach
    @if ($seo['og'] ?? true)
    <meta property="og:type" content="{{ $seo['og_type'] }}">
    <meta property="og:title" content="{{ $seo['og_title'] ?? $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['og_description'] ?? $seo['description'] }}">
    @if (! empty($seo['canonical']))<meta property="og:url" content="{{ $seo['canonical'] }}">@endif
    @if (! empty($seo['og_image']))<meta property="og:image" content="{{ $seo['og_image'] }}">@endif
    <meta property="og:locale" content="{{ $seo['locale'] }}">
    <meta property="og:site_name" content="{{ $currentWebsite->name ?? config('ofisvio.brand.name') }}">
    @endif
    @if (! array_key_exists('twitter', $seo))
    <meta name="twitter:card" content="summary">
    @elseif ($seo['twitter'] !== null)
    <meta name="twitter:card" content="{{ $seo['twitter']['card'] }}">
    @if ($seo['twitter']['site'] !== null)<meta name="twitter:site" content="{{ $seo['twitter']['site'] }}">@endif
    <meta name="twitter:title" content="{{ $seo['twitter']['title'] }}">
    <meta name="twitter:description" content="{{ $seo['twitter']['description'] }}">
    @if (! empty($seo['twitter']['image']))<meta name="twitter:image" content="{{ $seo['twitter']['image'] }}">@endif
    @endif
    @foreach ($seo['meta'] ?? [] as $m)<meta name="{{ $m['name'] }}" content="{{ $m['content'] }}">@endforeach
    @foreach ($seo['links'] ?? [] as $l)<link rel="{{ $l['rel'] }}" href="{{ $l['href'] }}"@if ($l['as'] !== null) as="{{ $l['as'] }}"@if ($l['as'] === 'font') crossorigin @endif @endif>@endforeach
    @if (! empty($seo['json_ld']))<script type="application/ld+json">{!! json_encode($seo['json_ld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>@endif
    @if ((($seo['gtm_id'] ?? '') !== '' || ($seo['ga4_id'] ?? '') !== '') && ($cookieConsent ?? null) !== \App\Site\CookieConsent::ALL)
    {{-- Analitik etiketleri rıza olmadan basılmaz (audit F-06, KVKK) --}}
    @elseif (($seo['gtm_id'] ?? '') !== '')
    {{-- Google Tag Manager (faz 44, verify.gtm_id) — bootstrap dış dosyada: satır içi nonce yok → ETag/304 önbelleği korunur --}}
    <script src="{{ asset_v('js/analytics.js') }}" data-gtm="{{ $seo['gtm_id'] }}"></script>
    @elseif (($seo['ga4_id'] ?? '') !== '')
    {{-- Google Analytics 4 (faz 44, verify.ga4_id) --}}
    <script src="{{ asset_v('js/analytics.js') }}" data-ga4="{{ $seo['ga4_id'] }}"></script>
    @endif
    @if (($seo['head_code'] ?? '') !== '')
    {{-- Geliştirici alanı: <head> özel kodu (JIT'li ayar, olduğu gibi) --}}
    {!! \App\Support\Csp::withNonce($seo['head_code']) !!}
    @endif
@endisset
