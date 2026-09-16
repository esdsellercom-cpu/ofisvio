{{-- CMS: yalnızca yayındaki yazılar. Yazı yoksa bölüm hiç basılmaz. --}}
@if ($posts->isNotEmpty())
<section id="blog" class="wrap section">
    <div class="section-head" style="margin-bottom:30px">
        <h2 class="h2" style="font-size:clamp(26px,3vw,36px)">{{ $texts['blog_title'] }}</h2>
        <a href="{{ route('site.posts') }}" style="font-size:15px;font-weight:600;color:var(--brand)">Tüm yazılar →</a>
    </div>
    <div class="grid-auto" style="--min:270px;--gap:22px">
        @foreach ($posts as $post)
            <a href="{{ route('site.post', $post->slug) }}" class="stack" style="gap:14px;min-width:0">
                <div class="shot" style="aspect-ratio:16/10;border-radius:var(--r-md);border:1px solid var(--line)">
                    <span class="shot__note">kapak · 800×500</span>
                </div>
                <div class="label">{{ $post->category }}@if ($post->reading_minutes) · {{ $post->reading_minutes }} dk @endif</div>
                <h3 class="h3" style="font-size:19px;line-height:1.25;text-wrap:pretty">{{ $post->title }}</h3>
                <p class="body-muted" style="margin:0;font-size:14.5px;color:var(--ink-muted)">{{ $post->excerpt }}</p>
            </a>
        @endforeach
    </div>
</section>
@endif