@extends('layouts.site')

{{-- Programatik hizmet × şehir sayfası (faz 60b): benzersiz giriş metni + hizmetin GEO cevapları + şube künyesi.
     Head (başlık, canonical, Service/LocalBusiness/Breadcrumb/FAQ şeması) SeoService::landingHead'den gelir. --}}
@section('content')
    @php($service = $landing->service)
    @php($location = $landing->location)
    <article class="wrap section" style="padding-top:64px">
        <p class="eyebrow"><a href="{{ route('site.services') }}">Çözümler</a> · <a href="{{ $service->path() }}">{{ $service->name }}</a> · {{ $location->city }}</p>
        <h1 class="h1" style="font-size:clamp(34px,5vw,56px)">{{ $landing->title }}</h1>

        <div class="grid-auto" style="--min:300px;--gap:32px;margin-top:36px;align-items:start">
            <div>
                @foreach (preg_split('/\r?\n\r?\n/', $landing->intro) as $paragraph)
                    <p class="{{ $loop->first ? 'lede' : '' }}" style="margin:0 0 14px;max-width:68ch;line-height:1.7">{{ trim($paragraph) }}</p>
                @endforeach

                @if ($landing->body)
                    <div class="prose">{!! \Illuminate\Support\Str::markdown($landing->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                @endif

                @include('site.partials.geo-answers', ['sections' => $sections, 'faq' => $landing->faqPairs()])

                @include('site.partials.entity-links', ['groups' => [
                    ['title' => 'Bu hizmet diğer şehirlerde', 'items' => $siblings, 'url' => fn ($p) => $p->path(), 'label' => fn ($p) => $p->location->city],
                    ['title' => 'İlgili yazılar', 'items' => $articles, 'url' => fn ($a) => $a->path(), 'label' => fn ($a) => $a->title],
                ]])
            </div>

            <aside class="panel stack" style="gap:14px">
                @if ($location->cover)
                    @include('site.partials.picture', ['media' => $location->cover, 'sizes' => '(max-width: 700px) 100vw, 360px', 'style' => 'width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:var(--r-md);display:block'])
                @endif
                <div>
                    <div class="label" style="margin-bottom:6px">Şube</div>
                    <div style="font-weight:600"><a href="{{ $location->path() }}">{{ $location->name }}</a></div>
                    @if ($location->address_line)<div class="small muted">{{ $location->address_line }}</div>@endif
                    <div class="small muted">{{ implode(' · ', array_filter([$location->district, $location->city])) }}</div>
                </div>
                @if ($location->phone)
                    <div><div class="label" style="margin-bottom:6px">Telefon</div><a href="tel:{{ preg_replace('/\D+/', '', $location->phone) }}">{{ $location->phone }}</a></div>
                @endif
                @if ($service->price_text)
                    <div><div class="label" style="margin-bottom:6px">Fiyat</div><div class="mono" style="color:var(--brand);font-weight:600">{{ $service->price_text }}</div></div>
                @endif
                <a href="{{ $service->path() }}" class="btn btn--ghost btn--block">{{ $service->name }} hakkında</a>
                <a href="{{ route('site.home') }}#teklif" class="btn btn--brand btn--block">{{ $texts['cta_header'] }}</a>
            </aside>
        </div>
    </article>
@endsection
