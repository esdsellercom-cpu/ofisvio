@extends('layouts.site')

@section('content')
    <section class="wrap section" style="padding-top:64px">
        <p class="eyebrow">Hizmetler</p>
        <h1 class="h2">{{ $services->count() }} hizmet</h1>
        <div class="grid-auto" style="margin-top:28px">
            @foreach ($services as $service)
                <a href="{{ $service->path() }}" class="card card--link">
                    @if ($service->cover)@include('site.partials.picture', ['media' => $service->cover, 'sizes' => '(max-width: 640px) 100vw, 320px', 'style' => 'width:100%;aspect-ratio:4/3;object-fit:cover;display:block', 'le' => ofv_le('service', $service->id, 'cover', null, 'Hizmet → '.$service->name.' kapağı', $service->cover_media_id, $service->name)])@elseif (ofv_live())<div class="shot" style="aspect-ratio:4/3;border-bottom:1px dashed var(--line);display:flex;align-items:center;justify-content:center;color:var(--ink-faint)"{!! ofv_le('service', $service->id, 'cover', null, 'Hizmet → '.$service->name.' kapağı (boş)', null, $service->name) !!}>Kapak ekle</div>@endif
                    <div class="card__body">
                        <span class="h3">{{ $service->name }}</span>
                        <span class="body-muted" style="font-size:14.5px">{{ $service->summary }}</span>
                        <div class="card__foot"><span class="mono small" style="color:var(--brand)">{{ $service->price_text ?? ($service->booking_kind ? 'saatlik · rezervasyon' : '') }}</span></div>
                    </div>
                </a>
            @endforeach
        </div>
    </section>
@endsection