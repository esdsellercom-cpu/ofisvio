{{-- Global header (faz 61a): yapılandırma $header (SiteChromeService; panel: /panel/ayarlar/header). Menü boşsa yayınlanmış
     bölüm çapaları ($siteNavLinks, görsel editörde metin olarak düzenlenir). Sticky/şeffaf/yükseklik/renk sınıf + CSS değişkeni. --}}
@php($onHome = request()->routeIs('site.home', 'site.preview'))
@php($currentPath = '/'.trim(request()->path(), '/'))
@php($autoLinks = array_map(fn ($l) => ['label' => $l['label'], 'href' => ($onHome ? '' : route('site.home')).$l['href'], 'key' => $l['key'] ?? '', 'children' => [], 'mega' => false, 'new_tab' => false], $siteNavLinks))
@php($menu = $header['menu'] !== [] ? array_map(fn ($m) => $m + ['key' => ''], $header['menu']) : $autoLinks)
@php($isActive = fn (string $href) => str_starts_with($href, '/') && ($href === $currentPath || ($href !== '/' && str_starts_with($currentPath, rtrim($href, '/').'/'))))
@php($styleVars = implode(';', array_filter([
    '--hdr-h:'.$header['height'].'px',
    $header['colors']['bg'] !== '' ? '--hdr-bg:'.$header['colors']['bg'] : null,
    $header['colors']['text'] !== '' ? '--hdr-ink:'.$header['colors']['text'] : null,
    $header['colors']['hover'] !== '' ? '--hdr-hover:'.$header['colors']['hover'] : null,
    $header['colors']['active'] !== '' ? '--hdr-active:'.$header['colors']['active'] : null,
])))
@php($ctaLabel = $header['cta']['label'] !== '' ? $header['cta']['label'] : $texts['cta_header'])

<header class="site-header{{ $header['sticky'] ? '' : ' site-header--static' }}{{ $header['transparent'] && $onHome ? ' site-header--transparent' : '' }} site-header--active-{{ $header['active_style'] }}" style="{{ $styleVars }}"{!! ofv_editor() ? ' data-ofv-global-area="header"' : '' !!}{!! ofv_live() ? ' data-le-area="header" data-le-area-label="Header (tüm sitede)"' : '' !!}>
    <div class="wrap site-header__inner">
        <a href="{{ route('site.home') }}" class="brand" aria-label="{{ $brand['name'] }}">
            @if ($header['logo'])
                <img src="{{ $header['logo']->urlFor(400) }}" alt="{{ $header['logo']->alt ?: $brand['name'] }}" class="brand__logo{{ $header['logo_mobile'] ? ' brand__logo--desktop' : '' }}" style="height:{{ $header['logo_height'] }}px;width:auto;display:block" decoding="async">
                @if ($header['logo_mobile'])<img src="{{ $header['logo_mobile']->urlFor(200) }}" alt="{{ $header['logo_mobile']->alt ?: $brand['name'] }}" class="brand__logo brand__logo--mobile" style="height:{{ $header['logo_height'] }}px;width:auto" decoding="async">@endif
            @else
                <span class="brand__mark" aria-hidden="true"></span>
                <span class="brand__name"{!! ofv_editor() ? ' data-ofv-brand="name"' : '' !!}>{{ $brand['name'] }}</span>
            @endif
        </a>

        <nav class="nav-main" aria-label="Ana menü">
            @foreach ($menu as $item)
                @if ($item['children'] !== [])
                    <div class="nav-item has-children{{ $item['mega'] ? ' is-mega' : '' }}">
                        <a href="{{ $item['href'] }}"{!! $isActive($item['href']) ? ' class="is-active"' : '' !!} aria-haspopup="true">{{ $item['label'] }} <span aria-hidden="true">▾</span></a>
                        <div class="nav-dropdown{{ $item['mega'] ? ' nav-dropdown--mega' : '' }}">
                            @foreach ($item['children'] as $child)
                                <a href="{{ $child['href'] }}" class="nav-dropdown__item{{ $isActive($child['href']) ? ' is-active' : '' }}"><span>{{ $child['label'] }}</span>@if ($item['mega'] && $child['description'] !== '')<small>{{ $child['description'] }}</small>@endif</a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <a href="{{ $item['href'] }}"{!! $isActive($item['href']) ? ' class="is-active"' : '' !!}{!! $item['new_tab'] ? ' target="_blank" rel="noopener"' : '' !!}{!! $item['key'] !== '' ? ofv_global('texts.'.$item['key']) : '' !!}>{{ $item['label'] }}</a>
                @endif
            @endforeach
        </nav>

        <div class="site-header__actions">
            @if ($header['show_phone'] && $brand['phone'])<a href="{{ $brand['phone_href'] }}" class="site-header__contact mono">{{ $brand['phone'] }}</a>@endif
            @if ($header['show_email'] && $brand['email'])<a href="mailto:{{ $brand['email'] }}" class="site-header__contact mono">{{ $brand['email'] }}</a>@endif
            @foreach ($header['social'] as $social)<a href="{{ $social['url'] }}" class="site-header__social" target="_blank" rel="noopener">@include('site.partials.social-icon', ['network' => $social['network']])</a>@endforeach
            <button type="button" class="btn btn--ghost btn--pill nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="nav-panel">Menü</button>
            @if ($header['show_login'])
                {{-- Giriş, panelin tenant akışına gider: oturum açan kullanıcı EnsureTenantContext ile organizasyon seçimine yönlendirilir. --}}
                @auth<a href="{{ route('panel.dashboard') }}" class="site-header__login">Panel</a>@else<a href="{{ route('login') }}" class="site-header__login">Giriş Yap</a>@endauth
            @endif
            <a href="{{ $header['cta']['href'] }}" class="btn btn--{{ $header['cta']['style'] }} btn--pill"{!! $header['cta']['label'] === '' ? ofv_global('texts.cta_header') : '' !!}>{{ $ctaLabel }}</a>
        </div>
    </div>

    <div class="nav-panel" id="nav-panel" data-nav-panel hidden>
        <div class="wrap" style="padding-block:22px 14px;display:flex;flex-wrap:wrap;gap:10px">
            @foreach ($menu as $item)
                <a href="{{ $item['href'] }}" class="chip{{ $isActive($item['href']) ? ' is-active' : '' }}">{{ $item['label'] }}</a>
                @foreach ($item['children'] as $child)<a href="{{ $child['href'] }}" class="chip chip--sub">{{ $child['label'] }}</a>@endforeach
            @endforeach
        </div>
        @if ($header['mobile_show_locations'] && ($singleLocation ?? null))
            <div class="wrap" style="padding-block:8px 30px">
                <div class="label" style="color:var(--brand);padding-bottom:12px;border-bottom:1px solid var(--line);margin-bottom:12px">{{ $singleLocation->city }}</div>
                <a href="{{ route('site.location', $singleLocation->slug) }}" style="font-size:14.5px">{{ $singleLocation->name }}</a>
                @if ($singleLocation->address_line)<div class="small muted" style="margin-top:4px">{{ $singleLocation->address_line }}</div>@endif
            </div>
        @elseif ($header['mobile_show_locations'] && isset($regions))
            <div class="wrap grid-auto" style="--min:190px;--gap:24px 32px;padding-block:8px 30px">
                @foreach ($regions as $regionName => $items)
                    <div>
                        <div class="label" style="color:var(--brand);padding-bottom:12px;border-bottom:1px solid var(--line);margin-bottom:12px">{{ $regionName }}</div>
                        <div class="stack" style="gap:8px">@foreach ($items as $loc)<a href="#lokasyonlar" style="font-size:14.5px">{{ $loc->name }}</a>@endforeach</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</header>
