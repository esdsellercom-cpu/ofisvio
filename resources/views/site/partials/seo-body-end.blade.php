{{-- Geliştirici alanı (faz 44): <body> bitiş kodu. Olduğu gibi basılır (JIT'li ayar). --}}
@isset($seo)
    @if (($seo['body_end'] ?? '') !== ''){!! $seo['body_end'] !!}@endif
@endisset
