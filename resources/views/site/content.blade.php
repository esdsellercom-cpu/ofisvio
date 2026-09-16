@extends($siteLayout)

@section('title', ($content->meta_title ?: $content->title).' — '.$currentWebsite->name)
@section('description', $content->meta_description ?: ($content->excerpt ?: ''))

@section('content')
    <article class="wrap section" style="padding-top:64px;max-width:760px">
        @if ($isPost)
            <p class="eyebrow">
                <a href="{{ route('site.posts') }}">Günlük</a>
                @if ($content->category) · <a href="{{ route('site.category', \Illuminate\Support\Str::slug($content->category)) }}">{{ $content->category }}</a> @endif
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

        @if ($isPost && ! empty($content->tags))
            <p class="row-actions" style="margin:28px 0 0;flex-wrap:wrap;gap:8px" aria-label="Etiketler">
                @foreach ($content->tags as $tag)
                    <a href="{{ route('site.tag', \Illuminate\Support\Str::slug($tag)) }}" class="tag">#{{ $tag }}</a>
                @endforeach
            </p>
        @endif
    </article>

    {{-- İlgili yazılar (faz 23 iç bağlantı): önce aynı kategori, sonra en yeni. --}}
    @if ($isPost && isset($related) && $related->isNotEmpty())
        <section class="wrap section" style="padding-top:0;max-width:760px" aria-labelledby="related-heading">
            <p class="eyebrow">Devamında</p>
            <h2 class="h3" id="related-heading">İlgili yazılar</h2>
            <ul class="stack" style="gap:14px;margin:18px 0 0;padding:0;list-style:none">
                @foreach ($related as $post)
                    <li>
                        <a href="{{ route('site.post', $post->slug) }}" style="font-weight:600">{{ $post->title }}</a>
                        <div class="small muted">{{ $post->category }}@if ($post->reading_minutes) · {{ $post->reading_minutes }} dk @endif</div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif@endsection
