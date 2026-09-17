{{-- Menü bağlantıları İZNE göre gösterilir, "personel mi"ye göre değil:
     finance_admin personeldir ama KYC kuyruğunu göremez. Gate::after boş
     context'te de global izinleri doğru cevaplar (organizasyon seçilmeden).
     Gruplar özelliğe göre: Müşteri işleri · İçerik · Site · CRM · Sistem. --}}
@php($active = fn (string ...$patterns) => collect($patterns)->contains(fn ($p) => request()->routeIs($p)) ? 'aria-current="page"' : '')
@php($kindIs = fn (string $kind) => request()->routeIs('panel.content.index', 'panel.content.create', 'panel.content.show', 'panel.content.edit') && request()->query('kind') === $kind)

@isset($activeOrganization)
    <div class="panel-nav__group">
        <span class="panel-nav__label">Müşteri</span>
        <a href="{{ route('panel.dashboard') }}" {!! $active('panel.dashboard') !!}>Genel bakış</a>
        <a href="{{ route('panel.companies.index') }}" {!! $active('panel.companies.*') !!}>Şirketler</a>
        @can('kyc.view_status')
            <a href="{{ route('panel.kyc.queue') }}" {!! $active('panel.kyc.queue') !!}>KYC kuyruğu</a>
        @endcan
    </div>
@endisset

@canany(['content.view', 'content.edit', 'content.review', 'content.approve', 'content.publish', 'content.schedule', 'content.archive'])
    <div class="panel-nav__group">
        <span class="panel-nav__label">İçerik</span>
        <a href="{{ route('panel.content.index', ['kind' => 'page']) }}" {!! $kindIs('page') ? 'aria-current="page"' : '' !!}>Sayfalar</a>
        <a href="{{ route('panel.content.index', ['kind' => 'post']) }}" {!! $kindIs('post') ? 'aria-current="page"' : '' !!}>Yazılar</a>
        <a href="{{ route('panel.content.calendar') }}" {!! $active('panel.content.calendar') !!}>Takvim</a>
        @can('content.publish')
            <a href="{{ route('panel.content.blocks') }}" {!! $active('panel.content.blocks') !!}>Ana sayfa</a>
        @endcan
        @can('content.edit')
            <a href="{{ route('panel.content.menu') }}" {!! $active('panel.content.menu') !!}>Menü &amp; tema</a>
        @endcan
        @canany(['content.edit', 'content.publish'])
            <a href="{{ route('panel.content.media.index') }}" {!! $active('panel.content.media.*') !!}>Medya</a>
        @endcanany
    </div>
@endcanany

@canany(['website.view', 'website.manage', 'seo.view', 'geo.view'])
    <div class="panel-nav__group">
        <span class="panel-nav__label">Site</span>
        @canany(['website.view', 'website.manage'])
            <a href="{{ route('panel.websites.index') }}" {!! $active('panel.websites.*') !!}>Websiteler</a>
        @endcanany
        @can('seo.view')
            <a href="{{ route('panel.seo.index') }}" {!! $active('panel.seo.*') !!}>SEO</a>
        @endcan
        @can('geo.view')
            <a href="{{ route('panel.geo.index') }}" {!! $active('panel.geo.*') !!}>GEO &amp; lokasyonlar</a>
        @endcan
    </div>
@endcanany

@if ($deskLocations->isNotEmpty() || auth()->user()?->can('lead.view') || auth()->user()?->can('booking.view'))
    <div class="panel-nav__group">
        <span class="panel-nav__label">CRM</span>
        @can('lead.view')
            <a href="{{ route('panel.leads.index') }}" {!! $active('panel.leads.*') !!}>Talepler</a>
        @endcan
        @can('booking.view')
            <a href="{{ route('panel.bookings.index') }}" {!! $active('panel.bookings.index') !!}>Rezervasyonlar</a>
        @endcan
        {{-- Lokasyon kapsamlı personel (resepsiyon): yalnız kendi şubesinin masası. --}}
        @foreach ($deskLocations as $deskLocation)
            <a href="{{ route('panel.bookings.location', $deskLocation) }}" {!! request()->routeIs('panel.bookings.location*') && request()->route('location')?->id === $deskLocation->id ? 'aria-current="page"' : '' !!}>{{ $deskLocation->name }} masası</a>
        @endforeach
    </div>
@endif

@canany(['notification.view', 'notification.manage', 'settings.view', 'settings.manage'])
    <div class="panel-nav__group">
        <span class="panel-nav__label">Yönetim</span>
        @canany(['notification.view', 'notification.manage'])
            <a href="{{ route('panel.notifications.index') }}" {!! $active('panel.notifications.index') !!}>Bildirim merkezi</a>
        @endcanany
        @canany(['settings.view', 'settings.manage'])
            <a href="{{ route('panel.settings.index') }}" {!! $active('panel.settings.*') !!}>Ayarlar</a>
        @endcanany
    </div>
@endcanany

@canany(['cache.view', 'user.manage', 'audit.view', 'performance.view'])
    <div class="panel-nav__group">
        <span class="panel-nav__label">Sistem</span>
        @can('performance.view')
            <a href="{{ route('panel.performance.index') }}" {!! $active('panel.performance.*') !!}>Performans</a>
        @endcan
        @can('audit.view')
            <a href="{{ route('panel.audit.index') }}" {!! $active('panel.audit.*') !!}>Denetim kaydı</a>
        @endcan
        @can('cache.view')
            <a href="{{ route('panel.cache.index') }}" {!! $active('panel.cache.*') !!}>Önbellek</a>
        @endcan
        @can('user.manage')
            <a href="{{ route('panel.users.index') }}" {!! $active('panel.users.*', 'panel.onboarding.*') !!}>Kullanıcılar</a>
        @endcan
    </div>
@endcanany

<div class="panel-nav__group">
    <span class="panel-nav__label">Hesap</span>
    <a href="{{ route('panel.context.select') }}" {!! $active('panel.context.*') !!}>Organizasyonlar</a>
    <a href="{{ route('panel.account') }}" {!! $active('panel.account*') !!}>Hesabım</a>
    <a href="{{ route('panel.notifications.inbox') }}" {!! $active('panel.notifications.inbox') !!}>Bildirimler @if ($unreadNotifications > 0)<span class="badge badge--warn">{{ $unreadNotifications }}</span>@endif</a>
</div>
