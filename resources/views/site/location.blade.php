@extends('layouts.site')

@section('content')
    <article class="wrap section" style="padding-top:64px">
        <p class="eyebrow"><a href="{{ route('site.locations') }}">Lokasyonlar</a> · {{ $location->region }}</p>
        <h1 class="h1" style="font-size:clamp(34px,5vw,56px)">{{ $location->name }}</h1>

        <div class="grid-auto" style="--min:300px;--gap:32px;margin-top:36px;align-items:start">
            <div>
                <div class="shot" style="aspect-ratio:16/10;border-radius:var(--r-lg);border:1px solid var(--line);margin-bottom:24px">
                    <span class="shot__note">{{ $location->badge }}</span>
                </div>

                @if ($location->geo_description)
                    <div class="prose">{!! $location->renderedDescription() !!}</div>
                @else
                    <p class="lede">{{ $location->address_line }}</p>
                @endif
            </div>

            <aside class="panel stack" style="gap:18px">
                <div>
                    <div class="label" style="margin-bottom:6px">Adres</div>
                    <div>{{ $location->address_line }}</div>
                    <div class="small muted">{{ trim(($location->district ? $location->district.', ' : '').$location->city.($location->postal_code ? ' '.$location->postal_code : '')) }}</div>
                </div>

                @if ($location->phone)
                    <div>
                        <div class="label" style="margin-bottom:6px">Telefon</div>
                        <a href="tel:{{ preg_replace('/\s+/', '', $location->phone) }}" class="mono">{{ $location->phone }}</a>
                    </div>
                @endif

                @if (! empty($location->opening_hours))
                    <div>
                        <div class="label" style="margin-bottom:6px">Çalışma saatleri</div>
                        <ul style="margin:0;padding-left:18px;font-size:14.5px">
                            @foreach ($location->opening_hours as $hours)<li class="mono">{{ $hours }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                @if (! empty($location->tags))
                    <div>
                        <div class="label" style="margin-bottom:8px">Çözümler</div>
                        <div style="display:flex;flex-wrap:wrap;gap:6px">
                            @foreach ($location->tags as $tag)<span class="tag">{{ $tag }}</span>@endforeach
                        </div>
                    </div>
                @endif

                @if ($location->price_from)
                    <div class="mono" style="color:var(--brand);font-size:15px">{{ $location->price_from }}</div>
                @endif

                @if ($location->hasCoordinates())
                    <a href="https://www.google.com/maps?q={{ $location->latitude }},{{ $location->longitude }}" target="_blank" rel="noopener" class="btn btn--ghost">Haritada aç ↗</a>
                @endif

                <a href="{{ route('site.home') }}#teklif" class="btn btn--brand">Tur planla</a>
            </aside>
        </div>
    </article>
@endsection
