{{-- Menü: yayınlanmış bölümlerin çapaları (SiteLayoutComposer); ana sayfa dışındaysa köke bağlanır. --}}
@php($onHome = request()->routeIs('site.home', 'site.preview'))
@php($navLinks = array_map(fn ($l) => ['label' => $l['label'], 'href' => ($onHome ? '' : route('site.home')).$l['href'], 'key' => $l['key'] ?? ''], $siteNavLinks))

<header class="site-header"{!! ofv_editor() ? ' data-ofv-global-area="header"' : '' !!}>
    <div class="wrap site-header__inner">
        <a href="{{ route('site.home') }}" class="brand">
            <span class="brand__mark" aria-hidden="true"></span>
            <span class="brand__name"{!! ofv_editor() ? ' data-ofv-brand="name"' : '' !!}>{{ $brand['name'] }}</span>
        </a>

        <nav class="nav-main" aria-label="Ana menü">
            @foreach ($navLinks as $link)
                <a href="{{ $link['href'] }}"{!! ofv_global('texts.'.$link['key']) !!}>{{ $link['label'] }}</a>
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
            <a href="#teklif" class="btn btn--brand btn--pill"{!! ofv_global('texts.cta_header') !!}>{{ $texts['cta_header'] }}</a>
        </div>
    </div>

    <div class="nav-panel" id="nav-panel" data-nav-panel hidden>
        <div class="wrap" style="padding-block:22px 14px;display:flex;flex-wrap:wrap;gap:10px">
            @foreach ($navLinks as $link)
                <a href="{{ $link['href'] }}" class="chip">{{ $link['label'] }}</a>
            @endforeach
        </div>
        @if ($singleLocation ?? null)
            <div class="wrap" style="padding-block:8px 30px">
                <div class="label" style="color:var(--brand);padding-bottom:12px;border-bottom:1px solid var(--line);margin-bottom:12px">{{ $singleLocation->city }}</div>
                <a href="{{ route('site.location', $singleLocation->slug) }}" style="font-size:14.5px">{{ $singleLocation->name }}</a>
                @if ($singleLocation->address_line)<div class="small muted" style="margin-top:4px">{{ $singleLocation->address_line }}</div>@endif
            </div>
        @elseif (isset($regions))
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
        @endif
    </div>
</header>
