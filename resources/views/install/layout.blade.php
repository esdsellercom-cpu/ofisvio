{{-- Kurulum sihirbazı kabuğu (faz 62): hiçbir view composer'a, ayara ya da veritabanına dokunmaz —
     kurulum anında settings/websites tabloları henüz yoktur. Yalnız config, asset_v() ve csp_nonce(). --}}
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Kurulum') — {{ config('ofisvio.brand.name') }}</title>
    <link rel="stylesheet" href="{{ asset_v('css/ofisvio.css') }}">
    <style nonce="{{ csp_nonce() }}">
        .setup-shell { max-width: 760px; margin: 0 auto; padding: 32px 16px 64px; }
        .setup-steps { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 18px; padding: 0; list-style: none; font-size: 13px; }
        .setup-steps li { padding: 4px 10px; border-radius: 999px; background: rgba(0, 0, 0, .06); }
        .setup-steps li[aria-current] { background: var(--brand, #14532d); color: #fff; }
        .setup-card { padding: 22px; border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(0, 0, 0, .08); }
        .setup-row { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid rgba(0, 0, 0, .06); }
        .setup-row:last-child { border-bottom: 0; }
        .setup-ok { color: #15803d; font-weight: 600; }
        .setup-bad { color: #b91c1c; font-weight: 600; }
        .setup-warn { color: #a16207; font-weight: 600; }
        .setup-note { font-size: 13px; color: #4b5563; }
        .setup-field { display: block; margin-bottom: 14px; }
        .setup-field span { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
        .setup-field input, .setup-field select { width: 100%; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 8px; font: inherit; }
        .setup-actions { display: flex; gap: 10px; align-items: center; margin-top: 18px; }
        .setup-error { margin: 0 0 14px; padding: 10px 12px; border-radius: 8px; background: #fee2e2; color: #991b1b; font-size: 14px; }
        .setup-notice { margin: 0 0 14px; padding: 10px 12px; border-radius: 8px; background: #dcfce7; color: #166534; font-size: 14px; }
    </style>
</head>
<body>
    <main class="setup-shell">
        <p class="eyebrow">{{ config('ofisvio.brand.name') }} · kurulum</p>
        <h1 class="h2">@yield('title', 'Kurulum')</h1>

        @hasSection('steps')
            @yield('steps')
        @endif

        @if ($errors->any())
            <div class="setup-error" role="alert">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        @if (session('install_notice'))
            <p class="setup-notice">{{ session('install_notice') }}</p>
        @endif

        <div class="setup-card">
            @yield('content')
        </div>

        <p class="setup-note" style="margin-top:18px">
            Bu ekran yalnız kurulum sırasında açıktır; kurulum bittiğinde adres kalıcı olarak kapanır.
        </p>
    </main>
</body>
</html>
