@extends('layouts.site')

@section('content')
    <article class="wrap section" style="padding-top:64px">
        <p class="eyebrow"><a href="{{ route('site.locations') }}">Lokasyonlar</a> · {{ $location->region }}</p>
        <h1 class="h1" style="font-size:clamp(34px,5vw,56px)">{{ $location->name }}</h1>

        <div class="grid-auto" style="--min:300px;--gap:32px;margin-top:36px;align-items:start">
            <div>
                {{-- Kapak: medya kütüphanesinden (yoksa boş durum kutusu; ticari içerik değil). --}}
                @if ($location->cover)
                    <figure style="margin:0 0 24px;border-radius:var(--r-lg);overflow:hidden;border:1px solid var(--line)">
                        @include('site.partials.picture', ['media' => $location->cover, 'sizes' => '(max-width: 700px) 100vw, 60vw', 'eager' => true, 'style' => 'width:100%;aspect-ratio:16/10;object-fit:cover;display:block'])
                        @if ($location->cover->caption)<figcaption class="small muted" style="padding:8px 12px">{{ $location->cover->caption }}</figcaption>@endif
                    </figure>
                @else
                    <div class="shot" style="aspect-ratio:16/10;border-radius:var(--r-lg);border:1px solid var(--line);margin-bottom:24px">
                        <span class="shot__note">{{ $location->badge }}</span>
                    </div>
                @endif

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
                @if ($location->hasCoordinates())
                    {{-- Harita gömme (faz 16): üçüncü taraf (OpenStreetMap) yalnız ziyaretçi isteyince yüklenir — KVKK/performans. JS kapalıysa üstteki bağlantı yeter. --}}
                    <button type="button" class="btn btn--ghost" data-map-load data-map-src="https://www.openstreetmap.org/export/embed.html?bbox={{ $location->longitude - 0.01 }},{{ $location->latitude - 0.006 }},{{ $location->longitude + 0.01 }},{{ $location->latitude + 0.006 }}&layer=mapnik&marker={{ $location->latitude }},{{ $location->longitude }}" data-map-target="location-map">Haritayı göster</button>
                @endif

                <a href="{{ route('site.home') }}#teklif" class="btn btn--brand">Tur planla</a>

                @if ($location->hasCoordinates())
                    <div id="location-map" hidden style="border:1px solid var(--line);border-radius:var(--r-md);overflow:hidden;aspect-ratio:4/3"></div>
                @endif
            </aside>
        </div>

        {{-- Galeri: kategori başına görseller (medya kütüphanesi); boşsa bölüm basılmaz. --}}
        @foreach ($gallery as $category => $items)
            <section style="margin-top:44px">
                <h2 class="label" style="margin:0 0 14px;color:var(--brand)">{{ \App\Models\LocationMedia::CATEGORIES[$category] ?? $category }}</h2>
                <div class="grid-auto" style="--min:240px;--gap:14px">
                    @foreach ($items as $item)
                        <figure style="margin:0;border-radius:var(--r-md);overflow:hidden;border:1px solid var(--line);background:var(--surface)">
                            <a href="{{ $item->media->url() }}" target="_blank" rel="noopener">
                                @include('site.partials.picture', ['media' => $item->media, 'sizes' => '(max-width: 640px) 100vw, (max-width: 1100px) 50vw, 33vw', 'style' => 'width:100%;aspect-ratio:4/3;object-fit:cover;display:block'])
                            </a>
                            @if ($item->media->title || $item->media->caption)
                                <figcaption class="small" style="padding:8px 12px">@if ($item->media->title)<strong>{{ $item->media->title }}</strong> @endif<span class="muted">{{ $item->media->caption }}</span></figcaption>
                            @endif
                        </figure>
                    @endforeach
                </div>
            </section>
        @endforeach
    </article>
@endsection
