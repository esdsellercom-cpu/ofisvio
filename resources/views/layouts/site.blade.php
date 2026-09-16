<!DOCTYPE html>
<html lang="tr" data-theme="{{ $currentWebsite?->theme ?? 'kum' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('site.partials.seo-head')
    @empty($seo)
        <title>@yield('title', config('ofisvio.brand.name').' — şirketinizin adresi bugün hazır olsun')</title>
        <meta name="description" content="@yield('description', 'Sanal ofis, hazır ofis ve coworking. Tescil adresi, çağrı ve kargo karşılama, saatlik toplantı odası.')">
    @endempty

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Instrument+Serif:ital@0;1&family=IBM+Plex+Mono:wght@400;500&display=swap">

    {{-- Varlık derlemesi yok: site `php artisan serve` ile doğrudan çalışır. --}}
    <link rel="stylesheet" href="{{ asset('css/ofisvio.css') }}">
</head>
<body>
    <a class="skip-link" href="#main">İçeriğe geç</a>

    @include('site.partials.topbar')
    @include('site.partials.header')

    <main id="main">
        @yield('content')
    </main>

    @include('site.partials.footer')

    <script src="{{ asset('js/ofisvio.js') }}" defer></script>
</body>
</html>
