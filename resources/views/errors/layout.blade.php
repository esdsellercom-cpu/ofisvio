<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') — {{ config('ofisvio.brand.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/ofisvio.css') }}">
</head>
<body>
    {{-- Hata sayfaları (audit: 403/404/419/429/500/503 gerçek durum, sahte başarı yok).
         Ayrıntı basılmaz: yol/config/istisna metni sızdırmaz. --}}
    <main class="wrap" style="min-height:70vh;display:grid;place-items:center;padding:64px 0">
        <div style="max-width:52ch;text-align:center">
            <p class="eyebrow">@yield('code')</p>
            <h1 class="h2">@yield('title')</h1>
            <p class="lede" style="margin:18px 0 0">@yield('message')</p>
            <div style="display:flex;gap:10px;justify-content:center;margin-top:28px;flex-wrap:wrap">
                <a href="{{ url('/') }}" class="btn btn--brand">Ana sayfa</a>
                @auth
                    <a href="{{ route('panel.dashboard') }}" class="btn btn--ghost">Panel</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn--ghost">Giriş</a>
                @endauth
            </div>
        </div>
    </main>
</body>
</html>
