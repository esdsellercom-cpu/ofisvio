{{-- Performance Command Center sekmeleri (faz 60f) --}}
<nav class="tabbar" aria-label="Performans" style="margin-bottom:16px">
    <a href="{{ route('panel.performance.center') }}" @if (request()->routeIs('panel.performance.center')) aria-current="page" @endif>Dashboard</a>
    <a href="{{ route('panel.cache.index') }}" @if (request()->routeIs('panel.cache.index')) aria-current="page" @endif>Cache Manager</a>
    <a href="{{ route('panel.performance.http-cache') }}" @if (request()->routeIs('panel.performance.http-cache')) aria-current="page" @endif>Cache politikaları & purge · HTTP cache</a>
    <a href="{{ route('panel.performance.redis') }}" @if (request()->routeIs('panel.performance.redis')) aria-current="page" @endif>Redis</a>
    <a href="{{ route('panel.performance.queries') }}" @if (request()->routeIs('panel.performance.queries')) aria-current="page" @endif>Sorgu performansı</a>
    <a href="{{ route('panel.performance.slow-queries') }}" @if (request()->routeIs('panel.performance.slow-queries')) aria-current="page" @endif>Yavaş sorgular</a>
    <a href="{{ route('panel.performance.assets') }}" @if (request()->routeIs('panel.performance.assets')) aria-current="page" @endif>Asset optimizasyonu</a>
    <a href="{{ route('panel.performance.vitals') }}" @if (request()->routeIs('panel.performance.vitals')) aria-current="page" @endif>Core Web Vitals</a>
    <a href="{{ route('panel.performance.audit') }}" @if (request()->routeIs('panel.performance.audit')) aria-current="page" @endif>Performans denetimi</a>
    <a href="{{ route('panel.performance.index') }}" @if (request()->routeIs('panel.performance.index')) aria-current="page" @endif>Baseline</a>
</nav>
