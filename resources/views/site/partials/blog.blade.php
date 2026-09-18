{{-- Blog / İçerikler (faz 58): yalnız yayındaki yazılar (öne çıkanlar önce, sonra en yeniler — HomeController). Yazı yoksa bölüm
     basılmaz. Ayarlar: başlık/açıklama (metinler ya da bölüm), adet, kategori, kart görünümü (grid | spotlight), CTA.
     Kart: gerçek kapak (Medya) — yoksa marka illüstrasyonu, kategori, başlık, özet, tarih, okuma süresi, Devamını oku.
     Editörde her kart seçilebilir (data-ofv-card) → sağ panelde yazıya gidiş. --}}
@php($d = \App\Site\SectionLibrary::defaults('blog'))
@php($layout = ($s['layout'] ?? $d['layout']) === 'spotlight' ? 'spotlight' : 'grid')
@php($cta = app(\App\Services\SiteBuilderService::class)->cta($s['cta'] ?? $d['cta'], $currentWebsite))
@php($lede = $s['lede'] ?? ($texts['blog_lede'] ?? ''))
@if ($posts->isNotEmpty())
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section" data-blog-section>
    <div class="section-head" style="margin-bottom:30px">
        <div style="min-width:0">
            <p class="eyebrow">Blog</p>
            <h2 class="h2" style="max-width:24ch"{!! ofv($s, 'title', 'texts.blog_title') !!}>{{ $s['title'] ?? $texts['blog_title'] }}</h2>
        </div>
        @if ($lede !== '' || ofv_editor())
            <p class="body-muted" style="margin:0;max-width:40ch;font-size:16px"{!! ofv($s, 'lede', 'texts.blog_lede') !!}>{{ $lede }}</p>
        @endif
    </div>

    <div class="grid-auto" style="--min:{{ $layout === 'spotlight' ? '300px' : '280px' }};--gap:22px" data-blog-cards>
        @foreach ($posts as $i => $post)
            @php($big = $layout === 'spotlight' && $i === 0)
            <article class="card card--link" style="min-width:0;{{ $big ? 'grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));' : '' }}"{!! ofv_editor() ? ' data-ofv-card="post:'.$post->id.'"' : '' !!}>
                <a href="{{ route('site.post', $post->slug) }}" style="display:block;min-width:0" aria-hidden="true" tabindex="-1">
                    @if ($post->cover_url)
                        <img src="{{ $post->cover_url }}" alt="{{ $post->title }}" loading="lazy" decoding="async" style="width:100%;aspect-ratio:{{ $big ? '4/3' : '16/10' }};height:{{ $big ? '100%' : 'auto' }};object-fit:cover;display:block">
                    @else
                        @include('site.partials.illustration', ['key' => 'blog', 'alt' => $post->title.' — yazı kapağı (illüstrasyon)', 'style' => 'width:100%;aspect-ratio:'.($big ? '4/3' : '16/10').';height:'.($big ? '100%' : 'auto').';object-fit:cover;display:block'])
                    @endif
                </a>
                <div class="card__body" style="padding:{{ $big ? '28px' : '18px 18px 20px' }};gap:10px">
                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                        @if ($post->category)<a href="{{ route('site.category', \Illuminate\Support\Str::slug($post->category)) }}" class="tag">{{ $post->category }}</a>@endif
                        <span class="mono small muted">{{ trim(($post->published_at ? $post->published_at->format('d.m.Y') : '').($post->reading_minutes ? ' · '.$post->reading_minutes.' dk okuma' : ''), ' · ') }}</span>
                    </div>
                    <h3 class="h3" style="font-size:{{ $big ? '24px' : '19px' }};line-height:1.25;text-wrap:pretty;margin:0"><a href="{{ route('site.post', $post->slug) }}">{{ $post->title }}</a></h3>
                    @if ($post->excerpt)<p class="body-muted" style="margin:0;font-size:14.5px;color:var(--ink-muted);flex:1">{{ \Illuminate\Support\Str::limit($post->excerpt, $big ? 220 : 140) }}</p>@endif
                    <div class="card__foot" style="margin-top:6px"><a href="{{ route('site.post', $post->slug) }}" style="font-size:14px;font-weight:600;color:var(--brand)">Devamını Oku →</a></div>
                </div>
            </article>
        @endforeach
    </div>

    @if ($cta)
        <p style="margin:28px 0 0;text-align:center"><a href="{{ $cta['href'] }}" class="btn btn--ghost btn--pill" @if ($cta['external']) target="_blank" rel="noopener" @endif>{{ $cta['label'] }}</a></p>
    @endif
</section>
@endif
