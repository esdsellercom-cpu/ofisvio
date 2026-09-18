@extends('layouts.site')

@section('content')
    <section class="wrap section" style="padding-top:64px">
        <p class="eyebrow">Lokasyonlar</p>
        <h1 class="h2">{{ $locations->count() }} şube · {{ $locations->pluck('city')->unique()->count() }} şehir</h1>

        @foreach ($locations->groupBy('region') as $region => $items)
            <h2 class="label" style="margin:36px 0 14px;color:var(--brand)">{{ $region }}</h2>
            <div class="grid-auto" style="--min:260px;--gap:18px">
                @foreach ($items as $location)
                    <a href="{{ route('site.location', $location->slug) }}" class="card card--link">
                        @if ($location->cover)
                            @include('site.partials.picture', ['media' => $location->cover, 'sizes' => '(max-width: 640px) 100vw, 320px', 'style' => 'width:100%;aspect-ratio:16/10;object-fit:cover;display:block', 'le' => ofv_le('location', $location->id, 'cover', null, 'Lokasyon → '.$location->name.' görseli', $location->cover_media_id, $location->city.' çalışma alanı')])
                        @endif
                        <div class="card__body">
                            <span class="h3">{{ $location->name }}</span>
                            <span class="body-muted" style="font-size:14px">{{ $location->address_line }}</span>
                            <div class="card__foot">
                                <span class="mono small" style="color:var(--brand)">{{ $location->price_from }}</span>
                                <span class="small muted">{{ implode(' · ', $location->serviceNames()) }}</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endforeach
    </section>
@endsection
