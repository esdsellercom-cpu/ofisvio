<!DOCTYPE html>
<html lang="tr" data-theme="{{ $currentWebsite?->theme ?? 'kum' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('site.partials.seo-head')
    @if (! empty($header['favicon']))<link rel="icon" type="{{ $header['favicon']->mime_type }}" href="{{ $header['favicon']->url() }}"><link rel="apple-touch-icon" href="{{ $header['favicon']->urlFor(400) }}">@endif
    @empty($seo)
        <title>@yield('title', $currentWebsite->name)</title>
    @endempty

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Instrument+Serif:ital@0;1&family=IBM+Plex+Mono:wght@400;500&display=swap">
    {{-- Tema v1: müşteri siteleri Ofisvio tasarım sistemini paylaşır; tema seçimi faz 10 devamı. --}}
    <link rel="stylesheet" href="{{ asset_v('css/ofisvio.css') }}">
</head>
<body>
    @include('site.partials.seo-body-start')
    <a class="skip-link" href="#main">İçeriğe geç</a>

    <header class="site-header">
        <div class="wrap site-header__inner">
            <a href="{{ route('site.home') }}" class="brand">
                <span class="brand__mark" aria-hidden="true"></span>
                <span class="brand__name">{{ $currentWebsite->name }}</span>
            </a>
            <nav class="nav-main" aria-label="Ana menü">
                @foreach ($tenantNav as $page)
                    <a href="{{ route('site.page', $page->slug) }}">{{ $page->title }}</a>
                @endforeach
                @if ($tenantHasPosts ?? false)<a href="{{ route('site.posts') }}">Yazılar</a>@endif
                @foreach ($currentWebsite->nav_links ?? [] as $link)
                    <a href="{{ $link['url'] }}"{!! str_starts_with($link['url'], 'http') ? ' target="_blank" rel="noopener"' : '' !!}>{{ $link['label'] }}</a>
                @endforeach
            </nav>
        </div>
    </header>

    <main id="main">
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="wrap" style="padding-block:28px;font-size:13.5px;display:flex;flex-wrap:wrap;gap:10px 24px;justify-content:space-between">
            <span>© {{ date('Y') }} {{ $brand['legal_name'] ?? $currentWebsite->name }}@if ($brand['tagline'] ?? '') · {{ $brand['tagline'] }}@endif</span>
            @if (($brand['phone'] ?? '') || ($brand['email'] ?? '') || ($brand['address'] ?? '') || ($brand['whatsapp'] ?? '') || ($brand['hours'] ?? []))
                <span class="mono" style="display:flex;gap:14px;flex-wrap:wrap">
                    @if ($brand['phone'])<a href="{{ $brand['phone_href'] }}">{{ $brand['phone'] }}</a>@endif
                    @if ($brand['email'])<a href="mailto:{{ $brand['email'] }}">{{ $brand['email'] }}</a>@endif
                    @if ($brand['address'])<span>{{ $brand['address'] }}</span>@endif
                    @if ($brand['whatsapp_href'])<a href="{{ $brand['whatsapp_href'] }}" target="_blank" rel="noopener">WhatsApp</a>@endif
                    @foreach ($brand['hours'] as $line)<span>{{ $line }}</span>@endforeach
                </span>
            @endif
            <span class="label">Altyapı: {{ config('ofisvio.brand.name') }}</span>
        </div>
    </footer>
    @include('site.partials.whatsapp')
    @if (! empty($cookieBannerNeeded))@include('site.partials.cookie-consent')@endif
    @include('site.partials.seo-body-end')
</body>
</html>
