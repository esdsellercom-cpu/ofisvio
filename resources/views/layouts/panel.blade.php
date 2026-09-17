<!DOCTYPE html>
{{-- Panel kabuğu (faz 38 — "Kolektif Panel" kalıbı): 252px kenar menüsü (gruplu, numaralı,
     rozetli) + üst çubuk (arama, organizasyon bağlamı, tema, bildirim zili, kullanıcı) +
     kaydırılan görünüm alanı. Menü/rozet/tema PanelLayoutComposer'dan; JS yalnız iyileştirir
     (menü açma, tema düğmesi) — kapalıyken her şey çalışır. Tarayıcı depolaması yok. --}}
<html lang="tr" class="panel" @if (! empty($uiTheme)) data-theme="{{ $uiTheme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">

    <title>@yield('title', 'Panel') — {{ config('ofisvio.brand.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="{{ asset_v('css/ofisvio.css') }}">
    <link rel="stylesheet" href="{{ asset_v('css/panel.css') }}">
    <script src="{{ asset_v('js/ofisvio.js') }}" defer></script>
    <script src="{{ asset_v('js/panel.js') }}" defer></script>
</head>
<body class="panel-body">
    <a class="skip-link" href="#main">İçeriğe geç</a>

    <div class="ap" id="ap" data-shell>
        <aside class="ap-side" id="ap-side" aria-label="Panel kenar çubuğu">
            <a href="{{ $twoFactorRequired ? route('panel.account') : ($isStaff ? route('panel.operations') : route('panel.context.select')) }}" class="ap-brand">
                <span class="ap-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr(config('ofisvio.brand.name'), 0, 1)) }}</span>
                <span><b>{{ config('ofisvio.brand.name') }}</b><span>Yönetim paneli</span></span>
            </a>

            <nav class="ap-nav" aria-label="Panel menüsü">
                @include('panel.partials.nav')
            </nav>

            <div class="ap-side__foot">
                @isset($activeOrganization)
                    <span>Organizasyon</span>
                    <b>{{ $activeOrganization->name }}</b>
                    @if ($canSwitchOrganization ?? false)
                        <a href="{{ route('panel.context.select') }}">Değiştir</a>
                    @endif
                @else
                    <span>{{ $isStaff ? 'Personel hesabı' : 'Müşteri hesabı' }}</span>
                @endisset
            </div>
        </aside>

        <div class="ap-main">
            <header class="ap-top">
                <button type="button" class="ap-hamb" data-shell-toggle aria-controls="ap-side" aria-expanded="false" aria-label="Menüyü aç/kapat">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M2 4h12M2 8h12M2 12h12"/></svg>
                </button>

                @unless ($twoFactorRequired)
                    <form class="ap-search" role="search" method="GET" action="{{ route('panel.search') }}">
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="7" cy="7" r="4.5"/><path d="m10.5 10.5 3 3" stroke-linecap="round"/></svg>
                        <input type="search" name="q" value="{{ request()->routeIs('panel.search') ? request()->query('q') : '' }}" placeholder="Rezervasyon, talep, şirket, kullanıcı ara…" aria-label="Panelde ara" minlength="2" maxlength="80">
                    </form>

                    @isset($activeOrganization)
                        <a href="{{ route('panel.context.select') }}" class="ap-ctx" title="Aktif organizasyon">
                            <span class="k">Organizasyon</span>
                            <b>{{ $activeOrganization->name }}</b>
                            @if ($canSwitchOrganization ?? false)<span class="sw">Değiştir</span>@endif
                        </a>
                    @endisset
                @endunless

                <span class="ap-spacer"></span>

                <span class="ap-theme">
                    <form method="POST" action="{{ route('panel.account.theme') }}" data-theme-form>
                        @csrf
                        <input type="hidden" name="theme" value="{{ ($uiTheme ?? null) === 'dark' ? 'light' : 'dark' }}">
                        <button type="submit" class="ap-icon" title="Temayı değiştir (şu an: {{ match ($uiTheme ?? null) { 'dark' => 'koyu', 'light' => 'açık', default => 'sistem' } }})" aria-label="Temayı değiştir">
                            <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="8" cy="8" r="3.2"/><path d="M8 1.5v1.6M8 12.9v1.6M1.5 8h1.6M12.9 8h1.6M3.4 3.4l1.1 1.1M11.5 11.5l1.1 1.1M3.4 12.6l1.1-1.1M11.5 4.5l1.1-1.1" stroke-linecap="round"/></svg>
                        </button>
                    </form>
                </span>

                <a href="{{ route('panel.notifications.inbox') }}" class="ap-icon" aria-label="Bildirimler{{ $unreadNotifications > 0 ? ' ('.$unreadNotifications.' okunmamış)' : '' }}" title="Bildirimler">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 11V7a4 4 0 0 1 8 0v4l1 1.5H3z" stroke-linejoin="round"/><path d="M6.5 13.5a1.5 1.5 0 0 0 3 0" stroke-linecap="round"/></svg>
                    @if ($unreadNotifications > 0)<span class="dot" aria-hidden="true"></span>@endif
                </a>

                <a href="{{ route('panel.account') }}" class="ap-who" title="Hesabım">
                    <span class="ap-av" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}{{ mb_strtoupper(mb_substr(Str::of(auth()->user()->name)->explode(' ')->last() ?? '', 0, 1)) }}</span>
                    <div><b>{{ auth()->user()->name }}</b><small>{{ $isStaff ? 'Personel' : 'Müşteri' }}</small></div>
                </a>

                <form method="POST" action="{{ route('logout') }}" class="ap-logout">
                    @csrf
                    <button type="submit">Çıkış</button>
                </form>
            </header>

            <main id="main" class="ap-view">
                <div class="ap-wrap">
                    @if (session('status'))
                        {{-- Fortify anahtar döner ('password-updated'); kendi controller'larımız cümle. --}}
                        <div class="notice" role="status" style="margin-bottom:18px">
                            <span class="notice__dot" aria-hidden="true"></span>
                            <div>{{ Lang::has('status.'.session('status')) ? __('status.'.session('status')) : session('status') }}</div>
                        </div>
                    @endif

                    @if (session('context_notice'))
                        <div class="notice notice--error" role="alert" style="margin-bottom:18px">
                            <span class="notice__dot" aria-hidden="true"></span>
                            <div>{{ session('context_notice') }}</div>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="notice notice--error" role="alert" style="margin-bottom:18px">
                            <span class="notice__dot" aria-hidden="true"></span>
                            <div>
                                @foreach ($errors->all() as $error)
                                    <div>{{ $error }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @yield('content')
                </div>
            </main>
        </div>
    </div>
    <div class="ap-backdrop" data-shell-close hidden></div>
</body>
</html>
