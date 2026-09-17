{{-- :::cta  title: … / text: … / button: Metin / link: /adres --}}
@php($f = $data['fields'])
<section class="content-block content-block--cta" style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between;padding:22px 26px;border-radius:var(--r-lg);background:var(--brand);color:#fff;margin:28px 0">
    <div style="min-width:0">
        @if (($f['title'] ?? '') !== '')<strong style="display:block;font-size:18px">{{ $f['title'] }}</strong>@endif
        @if (($f['text'] ?? '') !== '')<span style="opacity:.9">{{ $f['text'] }}</span>@endif
    </div>
    @if (($f['button'] ?? '') !== '' && ($f['link'] ?? '') !== '')<a href="{{ $f['link'] }}" class="btn btn--pill" style="background:#fff;color:var(--brand)">{{ $f['button'] }}</a>@endif
</section>
