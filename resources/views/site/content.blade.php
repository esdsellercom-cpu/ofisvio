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
            <p class="eyebrow">@if ($content->parent_slug)<a href="/{{ $content->parent_slug }}">{{ $content->parent?->title ?? $content->parent_slug }}</a>@else{{ $currentWebsite->name }}@endif</p>
        @endif

        @if (! empty($breadcrumbs) && count($breadcrumbs) > 1)
            {{-- Görünür breadcrumb (faz 44, links.breadcrumb_enabled); şema BreadcrumbList aynı listeden. --}}
            <nav class="small muted" aria-label="Gezinti izi" style="margin:0 0 10px">
                @foreach ($breadcrumbs as $crumb)
                    @if ($loop->last)<span aria-current="page">{{ $crumb['name'] }}</span>@else<a href="{{ $crumb['item'] }}">{{ $crumb['name'] }}</a> ›@endif
                @endforeach
            </nav>
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

        @if ($content->cover_url)
            <img src="{{ $content->cover_url }}" alt="{{ $content->cover?->alt ?? '' }}" style="width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:var(--r-lg);margin-top:28px"{!! ofv_le('content', $content->id, 'cover', null, ($content->kind->value === 'post' ? 'Blog → ' : 'Sayfa → ').$content->title.' kapağı', $content->cover_media_id, $content->title) !!}>
        @elseif (ofv_live())
            <div class="shot" style="aspect-ratio:16/9;border:1px dashed var(--line);border-radius:var(--r-lg);margin-top:28px;display:flex;align-items:center;justify-content:center;color:var(--ink-faint)"{!! ofv_le('content', $content->id, 'cover', null, ($content->kind->value === 'post' ? 'Blog → ' : 'Sayfa → ').$content->title.' kapağı (boş)', null, $content->title) !!}>Kapak görseli ekle</div>
        @endif

        {{-- renderedBody() markdown'ı süzülmüş HTML'e çevirir (ham HTML strip, güvensiz link yok). --}}
        <div class="prose" style="margin-top:36px">{!! $bodyHtml ?? $content->renderedBody() !!}</div>

        @if ($isPost && ! empty($content->tags))
            <p class="row-actions" style="margin:28px 0 0;flex-wrap:wrap;gap:8px" aria-label="Etiketler">
                @foreach ($content->tags as $tag)
                    <a href="{{ route('site.tag', \Illuminate\Support\Str::slug($tag)) }}" class="tag">#{{ $tag }}</a>
                @endforeach
            </p>
        @endif

        @if (! $isPost && isset($children) && $children->isNotEmpty())
            <section style="margin-top:36px" aria-labelledby="children-heading">
                <p class="eyebrow" id="children-heading">Alt sayfalar</p>
                <ul class="stack" style="gap:10px;margin:12px 0 0;padding:0;list-style:none">
                    @foreach ($children as $child)
                        <li><a href="{{ $child->path() }}" style="font-weight:600">{{ $child->title }}</a>@if ($child->excerpt)<div class="small muted">{{ $child->excerpt }}</div>@endif</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </article>

    {{-- İlgili yazılar (faz 23 iç bağlantı): önce aynı kategori, sonra en yeni. --}}
    @if ($isPost && ($showRelated ?? true) && isset($related) && $related->isNotEmpty())
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
    @endif
@endsection
