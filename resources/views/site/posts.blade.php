@extends($siteLayout)

@section('title', 'Yazılar — '.($currentWebsite?->name ?? config('ofisvio.brand.name')))
@section('description', 'Sanal ofis, tescil, hibrit çalışma ve mevzuat üzerine Ofisvio yazıları.')

@section('content')
    <section class="wrap section" style="padding-top:64px">
        <p class="eyebrow">Günlük</p>
        <h1 class="h2">Çalışma kültürü günlüğü</h1>

        @if (! empty($categories))
            <nav class="row-actions" style="margin-top:18px;flex-wrap:wrap;gap:8px" aria-label="Kategoriler">
                @foreach ($categories as $slug => $cat)
                    <a href="{{ route('site.category', $slug) }}" class="btn btn--ghost btn--pill{{ ($activeCategory ?? null) === $slug ? ' is-active' : '' }}">{{ $cat['name'] }} <span class="muted">({{ $cat['count'] }})</span></a>
                @endforeach
            </nav>
        @endif
        @if ($posts->isEmpty())
            <div class="empty-state" style="margin-top:32px">Henüz yayınlanmış yazı yok.</div>
        @else
            <div class="grid-auto" style="--min:270px;--gap:22px;margin-top:36px">
                @foreach ($posts as $post)
                    <a href="{{ route('site.post', $post->slug) }}" class="stack" style="gap:14px;min-width:0">
                        @if ($post->cover_url)
                            <img src="{{ $post->cover_url }}" alt="" style="width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:var(--r-md);border:1px solid var(--line)" loading="lazy">
                        @else
                            <div class="shot" style="aspect-ratio:16/10;border-radius:var(--r-md);border:1px solid var(--line)">
                                <span class="shot__note">kapak · 800×500</span>
                            </div>
                        @endif
                        <div class="label">{{ $post->category }}@if ($post->reading_minutes) · {{ $post->reading_minutes }} dk @endif · {{ $post->published_at?->format('d.m.Y') }}</div>
                        <h2 class="h3" style="font-size:19px;line-height:1.25;text-wrap:pretty">{{ $post->title }}</h2>
                        <p class="body-muted" style="margin:0;font-size:14.5px;color:var(--ink-muted)">{{ $post->excerpt }}</p>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
