@extends($siteLayout)

@section('title', ($content->meta_title ?: $content->title).' — '.$currentWebsite->name)
@section('description', $content->meta_description ?: ($content->excerpt ?: ''))

@section('content')
    <article class="wrap section" style="padding-top:64px;max-width:760px">
        @if ($isPost)
            <p class="eyebrow">
                <a href="{{ route('site.posts') }}">Günlük</a>
                @if ($content->category) · {{ $content->category }} @endif
                @if ($content->reading_minutes) · {{ $content->reading_minutes }} dk @endif
            </p>
        @else
            <p class="eyebrow">{{ $currentWebsite->name }}</p>
        @endif

        <h1 class="h2">{{ $content->title }}</h1>

        @if ($content->excerpt)
            <p class="lede" style="margin:18px 0 0">{{ $content->excerpt }}</p>
        @endif

        <p class="small muted" style="margin:14px 0 0">
            Yayın: {{ $content->published_at?->format('d.m.Y') }}
            @if ($content->updated_at && $content->published_at && $content->updated_at->gt($content->published_at))
                · Güncelleme: {{ $content->updated_at->format('d.m.Y') }}
            @endif
        </p>

        {{-- renderedBody() markdown'ı süzülmüş HTML'e çevirir (ham HTML strip, güvensiz link yok). --}}
        <div class="prose" style="margin-top:36px">{!! $content->renderedBody() !!}</div>
    </article>
@endsection
