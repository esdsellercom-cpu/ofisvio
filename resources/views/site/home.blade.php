@extends('layouts.site')

@section('content')
    {{-- Ana sayfa = yayınlanmış bölüm listesi (sayfa kurucu). Her bölüm kendi veri kaynağını okur;
         sıra, görünürlük, çapa, zamanlama ve cihaz süzgeci panelden. --}}
    @if ($preview ?? false)
        <div class="wrap" style="padding-top:16px"><div class="notice" role="status"><span class="notice__dot" aria-hidden="true"></span><div><strong>Önizleme</strong> — yayınlanmamış taslak; bu bağlantı {{ \App\Services\SiteBuilderService::PREVIEW_MINUTES }} dakika geçerlidir ve indekslenmez.</div></div></div>
    @endif
    @foreach ($sections as $section)
        @php($s = $section['settings'])
        @php($anchor = $section['anchor'])
        <div class="site-section{{ $section['hide_on_mobile'] ? ' hide-mobile' : '' }}{{ $section['hide_on_desktop'] ? ' hide-desktop' : '' }}">
            @include('site.sections.'.$section['type'])
        </div>
    @endforeach
@endsection