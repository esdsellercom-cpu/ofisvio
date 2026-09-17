{{-- Özellikler: İkon | Başlık | Açıklama | /bağlantı satırları --}}
@php($items = collect((array) ($s['items'] ?? []))->map(fn ($l) => array_map('trim', explode('|', (string) $l, 4)))->filter(fn ($p) => ($p[1] ?? '') !== '')->values())
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section sec-inner">
    @if (! empty($s['title']))<h2 class="h2" style="max-width:24ch"{!! ofv($s, 'title') !!}>{{ $s['title'] }}</h2>@endif
    @if (! empty($s['lede']))<p class="body-muted" style="margin:10px 0 0;max-width:50ch;font-size:16px"{!! ofv($s, 'lede') !!}>{{ $s['lede'] }}</p>@endif
    <div class="grid-auto sec-grid" style="--min:240px;--gap:18px;margin-top:28px">
        @foreach ($items as $p)
            @php($link = trim((string) ($p[3] ?? '')))
            @php($safeLink = $link !== '' && (str_starts_with($link, '/') || str_starts_with($link, '#') || str_starts_with($link, 'https://')) ? $link : '')
            <div class="card" style="padding:22px">
                @if (($p[0] ?? '') !== '')<div style="font-size:26px;line-height:1;margin-bottom:12px" aria-hidden="true">{{ $p[0] }}</div>@endif
                <h3 class="h3" style="margin:0 0 6px;font-size:17px">@if ($safeLink)<a href="{{ $safeLink }}">{{ $p[1] }}</a>@else{{ $p[1] }}@endif</h3>
                @if (($p[2] ?? '') !== '')<p class="body-muted" style="margin:0;font-size:15px">{{ $p[2] }}</p>@endif
            </div>
        @endforeach
    </div>
</section>
