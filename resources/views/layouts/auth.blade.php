<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">

    <title>@yield('title', 'Giriş') — {{ config('ofisvio.brand.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Instrument+Serif:ital@0;1&family=IBM+Plex+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="{{ asset_v('css/ofisvio.css') }}">
</head>
<body class="auth-body">
    <main class="auth-shell">
        <a href="{{ route('site.home') }}" class="brand auth-brand">
            <span class="brand__mark" aria-hidden="true"></span>
            <span class="brand__name">{{ config('ofisvio.brand.name') }}</span>
        </a>

        <div class="panel auth-card">
            @yield('content')
        </div>

        <p class="auth-foot">
            @yield('foot')
        </p>
        @if (developer_credit() !== '')<p class="auth-foot small muted" style="margin-top:6px">Geliştirme: {{ developer_credit() }}</p>@endif
    </main>
</body>
</html>
