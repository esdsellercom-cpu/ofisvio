{{-- :::contact  title: … / text: … / phone: … / email: … / address: … / button: Metin / link: /adres --}}
@php($f = $data['fields'])
<section class="content-block content-block--contact" style="padding:22px 26px;border:1px solid var(--line);border-radius:var(--r-lg);margin:28px 0">
    @if (($f['title'] ?? '') !== '')<h2 class="h3" style="margin:0 0 8px">{{ $f['title'] }}</h2>@endif
    @if (($f['text'] ?? '') !== '')<p style="margin:0 0 12px">{{ $f['text'] }}</p>@endif
    <div class="stack mono small" style="gap:4px">
        @if (($f['phone'] ?? '') !== '')<a href="tel:{{ preg_replace('/\D+/', '', $f['phone']) }}">{{ $f['phone'] }}</a>@endif
        @if (($f['email'] ?? '') !== '')<a href="mailto:{{ $f['email'] }}">{{ $f['email'] }}</a>@endif
        @if (($f['address'] ?? '') !== '')<span>{{ $f['address'] }}</span>@endif
    </div>
    @if (($f['button'] ?? '') !== '' && ($f['link'] ?? '') !== '')<a href="{{ $f['link'] }}" class="btn btn--brand btn--pill" style="margin-top:14px">{{ $f['button'] }}</a>@endif
</section>
