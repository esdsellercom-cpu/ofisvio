<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('site.partials.seo-head')
    @empty($seo)
        <title>@yield('title', $currentWebsite->name)</title>
    @endempty

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Instrument+Serif:ital@0;1&family=IBM+Plex+Mono:wght@400;500&display=swap">
    {{-- Tema v1: müşteri siteleri Ofisvio tasarım sistemini paylaşır; tema seçimi faz 10 devamı. --}}
    <link rel="stylesheet" href="{{ asset('css/ofisvio.css') }}">
</head>
<body>
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
                <a href="{{ route('site.posts') }}">Yazılar</a>
            </nav>
        </div>
    </header>

    <main id="main">
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="wrap" style="padding-block:28px;font-size:13.5px;display:flex;flex-wrap:wrap;gap:10px 24px;justify-content:space-between">
            <span>© {{ date('Y') }} {{ $currentWebsite->name }}</span>
            <span class="label">Altyapı: {{ config('ofisvio.brand.name') }}</span>
        </div>
    </footer>
</body>
</html>
