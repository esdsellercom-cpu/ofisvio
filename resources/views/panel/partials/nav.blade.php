{{-- Menü, aktif organizasyon varsa gösterilir; seçim ekranında yalnızca çıkış vardır. --}}
@isset($activeOrganization)
    <a href="{{ route('panel.dashboard') }}" @if (request()->routeIs('panel.dashboard')) aria-current="page" @endif>Genel bakış</a>
    <a href="{{ route('panel.companies.index') }}" @if (request()->routeIs('panel.companies.*')) aria-current="page" @endif>Şirketler</a>
    @if ($isStaff ?? false)
        <a href="{{ route('panel.kyc.queue') }}" @if (request()->routeIs('panel.kyc.queue')) aria-current="page" @endif>KYC kuyruğu</a>
    @endif
@endisset
@if ($isStaff ?? false)
    <a href="{{ route('panel.onboarding.create') }}" @if (request()->routeIs('panel.onboarding.*')) aria-current="page" @endif>Yeni müşteri</a>
@endif
<a href="{{ route('panel.context.select') }}" @if (request()->routeIs('panel.context.*')) aria-current="page" @endif>Organizasyonlar</a>
