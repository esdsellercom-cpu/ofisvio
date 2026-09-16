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
@can('user.manage')
    <a href="{{ route('panel.onboarding.create') }}" @if (request()->routeIs('panel.onboarding.*')) aria-current="page" @endif>Yeni müşteri</a>
@endcan
<a href="{{ route('panel.context.select') }}" @if (request()->routeIs('panel.context.*')) aria-current="page" @endif>Organizasyonlar</a>
