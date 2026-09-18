{{-- CMS: yalnızca yayındaki yazılar. Yazı yoksa bölüm hiç basılmaz. Kategori süzgeci ve adet bölüm ayarından (faz 57). --}}
@if ($posts->isNotEmpty())
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section">
    <div class="section-head" style="margin-bottom:30px">
        <h2 class="h2" style="font-size:clamp(26px,3vw,36px)"{!! ofv($s, 'title', 'texts.blog_title') !!}>{{ $s['title'] ?? $texts['blog_title'] }}</h2>
        <a href="{{ route('site.posts') }}" style="font-size:15px;font-weight:600;color:var(--brand)">Tüm yazılar →</a>
    </div>
    @if (! empty($s['lede']) || ofv_editor())<p class="body-muted" style="margin:-16px 0 24px;max-width:50ch;font-size:16px"{!! ofv($s, 'lede') !!}>{{ $s['lede'] ?? '' }}</p>@endif
    <div class="grid-auto" style="--min:270px;--gap:22px">
        @foreach ($posts as $post)
            <a href="{{ route('site.post', $post->slug) }}" class="stack" style="gap:14px;min-width:0">
                @if ($post->cover_url)
                    <img src="{{ $post->cover_url }}" alt="{{ $post->title }}" loading="lazy" decoding="async" style="width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:var(--r-md);border:1px solid var(--line);display:block">
                @else
                    @include('site.partials.illustration', ['key' => 'blog', 'alt' => $post->title.' — yazı kapağı (illüstrasyon)', 'style' => 'width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:var(--r-md);border:1px solid var(--line);display:block'])
                @endif
                <div class="label">{{ $post->category }}@if ($post->reading_minutes) · {{ $post->reading_minutes }} dk @endif</div>
                <h3 class="h3" style="font-size:19px;line-height:1.25;text-wrap:pretty">{{ $post->title }}</h3>
                <p class="body-muted" style="margin:0;font-size:14.5px;color:var(--ink-muted)">{{ $post->excerpt }}</p>
            </a>
        @endforeach
    </div>
</section>
@endif