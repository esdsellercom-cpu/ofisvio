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

@can('lead.view')
    <div class="panel-nav__group">
        <span class="panel-nav__label">CRM</span>
        <a href="{{ route('panel.leads.index') }}" {!! $active('panel.leads.*') !!}>Talepler</a>
    </div>
@endcan

@canany(['cache.view', 'user.manage'])
    <div class="panel-nav__group">
        <span class="panel-nav__label">Sistem</span>
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
</div>
