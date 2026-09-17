@extends('layouts.site')

@section('title', 'Etkinlikler — '.config('ofisvio.brand.name'))
@section('description', 'Ofisvio topluluk etkinlikleri: atölyeler, buluşmalar ve eğitimler. Kayıt için etkinliği seçin.')

@section('content')
    <section class="wrap section" style="padding-top:64px">
        <p class="eyebrow">Topluluk</p>
        <h1 class="h2">{{ $events->count() > 0 ? $events->count().' yaklaşan etkinlik' : 'Etkinlikler' }}</h1>
        @if ($events->isEmpty())
            <p class="lede" style="margin-top:16px">Şu anda planlanmış etkinlik yok; yenileri eklendiğinde burada listelenir.</p>
        @else
            <div class="grid-auto" style="margin-top:28px">
                @foreach ($events as $event)
                    <a href="{{ $event->path() }}" class="card card--link">
                        @if ($event->cover)@include('site.partials.picture', ['media' => $event->cover, 'sizes' => '(max-width: 640px) 100vw, 320px', 'style' => 'width:100%;aspect-ratio:16/10;object-fit:cover;display:block'])@endif
                        <div class="card__body">
                            <span class="mono small" style="color:var(--brand)">{{ $event->starts_at->format('d.m.Y · H:i') }}</span>
                            <span class="h3">{{ $event->title }}</span>
                            @if ($event->summary)<span class="body-muted" style="font-size:14.5px">{{ $event->summary }}</span>@endif
                            <div class="card__foot"><span class="small muted">{{ $event->location?->name ?? 'Çevrimiçi' }}</span><span class="mono small">{{ $event->price > 0 ? money($event->price) : 'Ücretsiz' }}</span></div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
