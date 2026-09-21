{{-- Geliştirici alanı (faz 44): <body> başlangıç kodu + GTM noscript. Olduğu gibi basılır (JIT'li ayar). --}}
@isset($seo)
    @if (($seo['gtm_id'] ?? '') !== '' && ($cookieConsent ?? null) === \App\Site\CookieConsent::ALL)<noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $seo['gtm_id'] }}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>@endif
    @if (($seo['body_start'] ?? '') !== ''){!! \App\Support\Csp::withNonce($seo['body_start']) !!}@endif
@endisset
