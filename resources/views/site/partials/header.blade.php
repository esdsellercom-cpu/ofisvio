@php($brand = config('ofisvio.brand'))
@php($navLinks = [
    ['label' => 'Çözümler', 'href' => '#cozumler'],
    ['label' => 'Nasıl çalışır', 'href' => '#nasil'],
    ['label' => 'Lokasyonlar', 'href' => '#lokasyonlar'],
    ['label' => 'Toplantı & Etkinlik', 'href' => '#toplanti'],
    ['label' => 'Üyelikler', 'href' => '#uyelik'],
])

<header class="site-header">
    <div class="wrap site-header__inner">
        <a href="{{ route('site.home') }}" class="brand">
            <span class="brand__mark" aria-hidden="true"></span>
            <span class="brand__name">{{ $brand['name'] }}</span>
        </a>

        <nav class="nav-main" aria-label="Ana menü">
            @foreach ($navLinks as $link)
                <a href="{{ $link['href'] }}">{{ $link['label'] }}</a>
            @endforeach
        </nav>

        <div style="display:flex;align-items:center;gap:14px;flex:none;white-space:nowrap;margin-left:auto">
            <button type="button" class="btn btn--ghost btn--pill nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="nav-panel">
                Menü
            </button>
            {{-- Giriş, panelin tenant akışına gider: oturum açan kullanıcı
                 EnsureTenantContext ile organizasyon seçimine yönlendirilir. --}}
            @auth
                <a href="{{ route('panel.dashboard') }}" style="font-size:15px;font-weight:500">Panel</a>
            @else
                <a href="{{ route('login') }}" style="font-size:15px;font-weight:500">Giriş Yap</a>
            @endauth
            <a href="#teklif" class="btn btn--brand btn--pill">Teklif Al</a>
        </div>
    </div>

    <div class="nav-panel" id="nav-panel" data-nav-panel hidden>
        <div class="wrap" style="padding-block:22px 14px;display:flex;flex-wrap:wrap;gap:10px">
            @foreach ($navLinks as $link)
                <a href="{{ $link['href'] }}" class="chip">{{ $link['label'] }}</a>
            @endforeach
        </div>
        @isset($regions)
            <div class="wrap grid-auto" style="--min:190px;--gap:24px 32px;padding-block:8px 30px">
                @foreach ($regions as $regionName => $items)
                    <div>
                        <div class="label" style="color:var(--brand);padding-bottom:12px;border-bottom:1px solid var(--line);margin-bottom:12px">{{ $regionName }}</div>
                        <div class="stack" style="gap:8px">
                            @foreach ($items as $loc)
                                <a href="#lokasyonlar" style="font-size:14.5px">{{ $loc->name }}</a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endisset
    </div>
</header>
