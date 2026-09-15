<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">

    <title>@yield('title', 'Panel') — {{ config('ofisvio.brand.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Instrument+Serif:ital@0;1&family=IBM+Plex+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="{{ asset('css/ofisvio.css') }}">
</head>
<body class="panel-body">
    <a class="skip-link" href="#main">İçeriğe geç</a>

    <aside class="panel-side">
        <a href="{{ route('panel.dashboard') }}" class="brand">
            <span class="brand__mark" aria-hidden="true"></span>
            <span class="brand__name">{{ config('ofisvio.brand.name') }}</span>
        </a>

        @isset($activeOrganization)
            <div class="panel-context">
                <span class="label">Organizasyon</span>
                <span class="panel-context__name">{{ $activeOrganization->name }}</span>
                @if ($canSwitchOrganization ?? false)
                    <a href="{{ route('panel.context.select') }}">Değiştir</a>
                @endif
            </div>
        @endisset

        <nav class="panel-nav" aria-label="Panel menüsü">
            @include('panel.partials.nav')
        </nav>

        <div class="panel-user">
            <span><strong>{{ auth()->user()->name }}</strong><br><span class="muted">{{ auth()->user()->email }}</span></span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Çıkış yap</button>
            </form>
        </div>
    </aside>

    <main id="main" class="panel-main">
        @if (session('status'))
            <div class="notice" role="status" style="margin-bottom:22px">
                <span class="notice__dot" aria-hidden="true"></span>
                <div>{{ session('status') }}</div>
            </div>
        @endif

        @if (session('context_notice'))
            <div class="notice notice--error" role="alert" style="margin-bottom:22px">
                <span class="notice__dot" aria-hidden="true"></span>
                <div>{{ session('context_notice') }}</div>
            </div>
        @endif

        @if ($errors->any())
            <div class="notice notice--error" role="alert" style="margin-bottom:22px">
                <span class="notice__dot" aria-hidden="true"></span>
                <div>
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
