@extends($siteLayout)

@section('title', $categoryName.' yazıları — '.($currentWebsite?->name ?? config('ofisvio.brand.name')))
@section('description', $categoryName.' üzerine '.($currentWebsite?->name ?? config('ofisvio.brand.name')).' yazıları.')

@section('content')
    <section class="wrap section" style="padding-top:64px">
        <p class="eyebrow"><a href="{{ route('site.posts') }}">Günlük</a> · Kategori</p>
        <h1 class="h2">{{ $categoryName }}</h1>

        <nav class="row-actions" style="margin-top:18px;flex-wrap:wrap;gap:8px" aria-label="Kategoriler">
            @foreach ($categories as $slug => $cat)
                <a href="{{ route('site.category', $slug) }}" class="btn btn--ghost btn--pill{{ $slug === $categorySlug ? ' is-active' : '' }}" @if ($slug === $categorySlug) aria-current="page" @endif>{{ $cat['name'] }} <span class="muted">({{ $cat['count'] }})</span></a>
            @endforeach
        </nav>

        <div class="grid-auto" style="--min:270px;--gap:22px;margin-top:36px">
            @foreach ($posts as $post)
                <a href="{{ route('site.post', $post->slug) }}" class="stack" style="gap:14px;min-width:0">
                    @if ($post->cover_url)
                        <img src="{{ $post->cover_url }}" alt="{{ $post->title }}" style="width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:var(--r-md);border:1px solid var(--line)" loading="lazy"{!! ofv_le('content', $post->id, 'cover', null, 'Blog → '.$post->title.' kapağı', $post->cover_media_id, $post->title) !!}>
                    @else
                        <div class="shot" style="aspect-ratio:16/10;border-radius:var(--r-md);border:1px solid var(--line)"{!! ofv_le('content', $post->id, 'cover', null, 'Blog → '.$post->title.' kapağı (boş)', null, $post->title) !!}>
                            <span class="shot__note">kapak · 800×500</span>
                        </div>
                    @endif
                    <div class="label">{{ $post->category }}@if ($post->reading_minutes) · {{ $post->reading_minutes }} dk @endif · {{ $post->published_at?->format('d.m.Y') }}</div>
                    <h2 class="h3" style="font-size:19px;line-height:1.25;text-wrap:pretty">{{ $post->title }}</h2>
                    <p class="body-muted" style="margin:0;font-size:14.5px;color:var(--ink-muted)">{{ $post->excerpt }}</p>
                </a>
            @endforeach
        </div>
    </section>
@endsection
