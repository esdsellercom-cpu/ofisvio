@extends('layouts.site')

@section('content')
    <article class="wrap section" style="padding-top:64px">
        <p class="eyebrow"><a href="{{ route('site.services') }}">Hizmetler</a>@if ($service->is_flagship) · amiral ürün @endif</p>
        <h1 class="h1" style="font-size:clamp(34px,5vw,56px)">{{ $service->name }}</h1>
        @if ($service->summary)<p class="lede" style="margin:18px 0 0;max-width:60ch">{{ $service->summary }}</p>@endif

        <div class="grid-auto" style="--min:300px;--gap:32px;margin-top:36px;align-items:start">
            <div>
                @if ($service->cover)
                    <figure style="margin:0 0 24px;border-radius:var(--r-lg);overflow:hidden;border:1px solid var(--line)">@include('site.partials.picture', ['media' => $service->cover, 'sizes' => '(max-width: 700px) 100vw, 60vw', 'eager' => true, 'style' => 'width:100%;aspect-ratio:16/10;object-fit:cover;display:block'])</figure>
                @endif
                @if ($service->description)<div class="prose">{!! $service->renderedDescription() !!}</div>@endif

                {{-- Hizmet + lokasyon ilişkisi: bu hizmeti sunan yayındaki şubeler --}}
                <h2 class="label" style="margin:36px 0 14px;color:var(--brand)">Bu hizmeti sunan lokasyonlar ({{ $locations->count() }})</h2>
                @if ($locations->isEmpty())
                    <p class="body-muted">Şu anda bu hizmeti sunan yayında lokasyon yok.</p>
                @else
                    <div class="grid-auto" style="--min:220px;--gap:14px">
                        @foreach ($locations as $location)
                            <a href="{{ route('site.location', $location->slug) }}" class="card card--link">
                                @if ($location->cover)@include('site.partials.picture', ['media' => $location->cover, 'sizes' => '(max-width: 640px) 100vw, 260px', 'style' => 'width:100%;aspect-ratio:16/10;object-fit:cover;display:block'])@endif
                                <div class="card__body"><span class="h3">{{ $location->name }}</span><span class="small muted">{{ $location->city }} · {{ $location->region }}</span></div>
                            </a>
                        @endforeach
                    </div>
                @endif

                {{-- Rezervasyona bağlı hizmet: gerçek odalar --}}
                @if ($rooms->isNotEmpty())
                    <h2 class="label" style="margin:36px 0 14px;color:var(--brand)">Odalar</h2>
                    <div class="row-list">
                        @foreach ($rooms as $room)
                            <div class="row-list__item">
                                <div style="min-width:0"><div style="font-weight:600">{{ $room->name }} <span style="font-weight:400;color:var(--ink-soft)">· {{ $room->location->name }}</span></div><div class="small muted">{{ $room->capacity }} kişi · {{ $room->open_from }}–{{ $room->open_until }}</div></div>
                                <a href="{{ route('site.booking.index', ['lokasyon' => $room->location_id, 'oda' => $room->id]) }}" class="btn btn--ghost btn--pill">{{ number_format($room->hourly_rate, 0, ',', '.') }} ₺/saat · Rezerve et</a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            <aside class="panel stack" style="gap:14px">
                @if ($service->price_text)<div><div class="label" style="margin-bottom:6px">Fiyat</div><div class="mono" style="color:var(--brand);font-weight:600">{{ $service->price_text }}</div></div>@endif
                @if ($service->booking_kind)
                    <a href="{{ route('site.booking.index') }}" class="btn btn--brand btn--block">Uygun saatleri gör</a>
                @else
                    <a href="{{ route('site.home') }}#teklif" class="btn btn--brand btn--block">{{ $texts['cta_header'] }}</a>
                @endif
            </aside>
        </div>
    </article>
@endsection