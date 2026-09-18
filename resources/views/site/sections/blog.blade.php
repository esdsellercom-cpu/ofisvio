{{-- Bölüm: yazılar (adet bölüm ayarından; veri CMS'ten) --}}
@php($category = trim((string) ($s['category'] ?? '')))
@php($posts = ($category === '' ? $homePosts : $homePosts->filter(fn ($p) => mb_strtolower((string) $p->category) === mb_strtolower($category))->values())->take((int) ($s['limit'] ?? 3)))
@include('site.partials.blog')
