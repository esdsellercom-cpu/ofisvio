{{-- Bölüm: yazılar (adet bölüm ayarından; veri CMS'ten) --}}
@php($posts = $homePosts->take((int) ($s['limit'] ?? 3)))
@include('site.partials.blog')
