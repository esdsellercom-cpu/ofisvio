{{-- Çerez rıza bandı (audit F-06): yalnız 3. taraf etiket (GA4/GTM) tanımlıyken ve tercih yokken; metinler footer ayarından. --}}
@php($cn = $footer['cookie_notice'] ?? [])
@php($cookiesPage = collect($legalPages ?? [])->first(fn ($p) => (int) ($footer['legal']['cookies'] ?? 0) === $p->id))
<div class="cookie-bar" role="region" aria-label="Çerez tercihi" data-cookie-bar>
    <p class="cookie-bar__text">{{ ($cn['text'] ?? '') !== '' ? $cn['text'] : 'Bu site, deneyimi iyileştirmek ve ziyaret istatistiği üretmek için çerez kullanır. Zorunlu çerezler her zaman etkindir; analitik çerezler yalnız izninizle çalışır.' }}
        @if ($cookiesPage)<a href="{{ route('site.page', $cookiesPage->slug) }}">Çerez politikası</a>@endif
    </p>
    <form method="POST" action="{{ route('site.cookie-consent') }}" class="cookie-bar__actions" data-no-busy>
        @csrf
        <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
        <button type="submit" name="choice" value="essential" class="btn btn--ghost btn--pill">{{ ($cn['decline'] ?? '') !== '' ? $cn['decline'] : 'Yalnız zorunlu' }}</button>
        <button type="submit" name="choice" value="all" class="btn btn--brand btn--pill">{{ ($cn['accept'] ?? '') !== '' ? $cn['accept'] : 'Kabul et' }}</button>
    </form>
</div>
