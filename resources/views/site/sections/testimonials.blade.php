{{-- Referanslar: Ad | Şirket/Rol | Görüş --}}
@php($items = collect((array) ($s['items'] ?? []))->map(fn ($l) => array_map('trim', explode('|', (string) $l, 3)))->filter(fn ($p) => ($p[2] ?? '') !== '')->values())
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section sec-inner">
    @if (! empty($s['title']))<h2 class="h2" style="max-width:24ch;margin-bottom:24px"{!! ofv($s, 'title') !!}>{{ $s['title'] }}</h2>@endif
    <div class="grid-auto sec-grid" style="--min:260px;--gap:18px">
        @foreach ($items as $p)
            <figure class="card" style="padding:24px;margin:0">
                <blockquote style="margin:0;font-size:16.5px;line-height:1.6">&ldquo;{{ $p[2] }}&rdquo;</blockquote>
                <figcaption style="margin-top:14px;font-size:14px"><b>{{ $p[0] }}</b>@if (($p[1] ?? '') !== '') <span class="muted">· {{ $p[1] }}</span>@endif</figcaption>
            </figure>
        @endforeach
    </div>
</section>
