{{-- Menü bağlantıları İZNE göre gösterilir, "personel mi"ye göre değil:
     finance_admin personeldir ama KYC kuyruğunu göremez. Gate::after boş
     context'te de global izinleri doğru cevaplar (organizasyon seçilmeden). --}}
@isset($activeOrganization)
    <a href="{{ route('panel.dashboard') }}" @if (request()->routeIs('panel.dashboard')) aria-current="page" @endif>Genel bakış</a>
    <a href="{{ route('panel.companies.index') }}" @if (request()->routeIs('panel.companies.*')) aria-current="page" @endif>Şirketler</a>
    @can('kyc.view_status')
        <a href="{{ route('panel.kyc.queue') }}" @if (request()->routeIs('panel.kyc.queue')) aria-current="page" @endif>KYC kuyruğu</a>
    @endcan
@endisset
@canany(['content.view', 'content.edit', 'content.review', 'content.approve', 'content.publish', 'content.schedule', 'content.archive'])
    <a href="{{ route('panel.content.index', ['kind' => 'page']) }}" @if (request()->routeIs('panel.content.*') && request()->query('kind') === 'page') aria-current="page" @endif>Sayfalar</a>
    <a href="{{ route('panel.content.index', ['kind' => 'post']) }}" @if (request()->routeIs('panel.content.*') && request()->query('kind') === 'post') aria-current="page" @endif>Yazılar</a>
    <a href="{{ route('panel.content.calendar') }}" @if (request()->routeIs('panel.content.calendar')) aria-current="page" @endif>Takvim</a>
    @can('content.publish')
        <a href="{{ route('panel.content.blocks') }}" @if (request()->routeIs('panel.content.blocks')) aria-current="page" @endif>Ana sayfa</a>
    @endcan
@endcanany
@can('seo.view')
    <a href="{{ route('panel.seo.index') }}" @if (request()->routeIs('panel.seo.*')) aria-current="page" @endif>SEO</a>
@endcan
@can('geo.view')
    <a href="{{ route('panel.geo.index') }}" @if (request()->routeIs('panel.geo.*')) aria-current="page" @endif>GEO</a>
@endcan
@can('cache.view')
    <a href="{{ route('panel.cache.index') }}" @if (request()->routeIs('panel.cache.*')) aria-current="page" @endif>Önbellek</a>
@endcan
@canany(['website.view', 'website.manage'])
    <a href="{{ route('panel.websites.index') }}" @if (request()->routeIs('panel.websites.*')) aria-current="page" @endif>Websiteler</a>
@endcanany
@can('user.manage')
    <a href="{{ route('panel.users.index') }}" @if (request()->routeIs('panel.users.*') || request()->routeIs('panel.onboarding.*')) aria-current="page" @endif>Kullanıcılar</a>
@endcan
<a href="{{ route('panel.context.select') }}" @if (request()->routeIs('panel.context.*')) aria-current="page" @endif>Organizasyonlar</a>
