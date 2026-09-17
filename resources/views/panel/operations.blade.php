@extends('layouts.panel')

@section('title', 'Operasyon paneli')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Genel bakış · tüm lokasyonlar</p>
            <h1 class="h2">Operasyon paneli</h1>
            <p>Rezervasyon, tahsilat, üyelik, alan doluluğu, talepler, etkinlik ve franchise toplamları — organizasyondan bağımsız, gerçek servis verisi.</p>
        </div>
        <div class="panel-head__actions qa">
            @can('booking.view')<a href="{{ route('panel.bookings.index', ['sekme' => 'pending']) }}">Onay bekleyenler</a>@endcan
            @can('invoice.view')<a href="{{ route('panel.collections.index') }}">Tahsilat</a>@endcan
            @can('lead.view')<a href="{{ route('panel.leads.index', ['status' => 'new']) }}">Yeni talepler</a>@endcan
            @can('geo.edit')<a href="{{ route('panel.geo.create') }}">Yeni lokasyon</a>@endcan
            @canany(['notification.view', 'notification.manage'])<a href="{{ route('panel.notifications.index') }}">Bildirim merkezi</a>@endcanany
            @can('audit.view')<a href="{{ route('panel.audit.index') }}">Denetim kaydı</a>@endcan
        </div>
    </div>

    <div class="stack" style="gap:18px">
        @include('panel.partials.ops-overview')
    </div>
@endsection
