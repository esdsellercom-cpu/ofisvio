{{-- :::hero  title: … / text: … / button: Metin / link: /adres / image: /url  --}}
@php($f = $data['fields'])
<section class="content-block content-block--hero" style="display:grid;gap:22px;grid-template-columns:{{ ($f['image'] ?? '') !== '' ? '1.2fr 1fr' : '1fr' }};align-items:center;padding:28px;border-radius:var(--r-lg);background:var(--surface-warm, #f6f4ef);margin:28px 0">
    <div>
        @if (($f['eyebrow'] ?? '') !== '')<p class="eyebrow">{{ $f['eyebrow'] }}</p>@endif
        @if (($f['title'] ?? '') !== '')<h2 class="h2" style="margin:0 0 10px">{{ $f['title'] }}</h2>@endif
        @if (($f['text'] ?? '') !== '')<p class="lede" style="margin:0 0 18px">{{ $f['text'] }}</p>@endif
        @if (($f['button'] ?? '') !== '' && ($f['link'] ?? '') !== '')<a href="{{ $f['link'] }}" class="btn btn--brand btn--pill">{{ $f['button'] }}</a>@endif
    </div>
    @if (($f['image'] ?? '') !== '')<img src="{{ $f['image'] }}" alt="{{ $f['alt'] ?? $f['title'] ?? '' }}" loading="lazy" style="width:100%;border-radius:var(--r-lg);object-fit:cover">@endif
</section>
