@extends('layouts.tenant')

@section('title', $website->name)

@section('content')
    <section class="wrap section" style="padding-top:64px">
        <p class="eyebrow">{{ $website->name }}</p>
        <h1 class="h1" style="font-size:clamp(34px,5vw,56px)">{{ $website->name }}</h1>

        @if ($pages->isEmpty() && $posts->isEmpty())
            <div class="empty-state" style="margin-top:32px">Bu sitede henüz yayınlanmış içerik yok.</div>
        @endif

        @if ($pages->isNotEmpty())
            <div class="grid-auto" style="--min:220px;--gap:16px;margin-top:36px">
                @foreach ($pages as $page)
                    <a href="{{ route('site.page', $page->slug) }}" class="card card--link"><div class="card__body"><span class="h3">{{ $page->title }}</span>@if ($page->excerpt)<span class="body-muted">{{ $page->excerpt }}</span>@endif</div></a>
                @endforeach
            </div>
        @endif

        @if ($posts->isNotEmpty())
            <h2 class="h2" style="font-size:clamp(24px,3vw,34px);margin-top:56px">Yazılar</h2>
            <div class="grid-auto" style="--min:270px;--gap:22px;margin-top:24px">
                @foreach ($posts as $post)
                    <a href="{{ route('site.post', $post->slug) }}" class="stack" style="gap:10px;min-width:0">
                        <div class="label">{{ $post->category }} · {{ $post->published_at?->format('d.m.Y') }}</div>
                        <h3 class="h3" style="font-size:19px;line-height:1.25">{{ $post->title }}</h3>
                        <p class="body-muted" style="margin:0;font-size:14.5px">{{ $post->excerpt }}</p>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
