@if (! empty($blocks['amenities']))
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section">
    <p class="eyebrow">05 — Dahil olanlar</p>
    <h2 class="h2" style="max-width:26ch;margin-bottom:32px"{!! ofv($s, 'title', 'texts.amenities_title') !!}>{{ $s['title'] ?? $texts['amenities_title'] }}</h2>
    <div class="grid-auto" style="--min:270px;--gap:1px;background:var(--line);border:1px solid var(--line);border-radius:var(--r-lg);overflow:hidden">
        @foreach ($blocks['amenities'] as $item)
            <div style="background:var(--surface);padding:24px 22px 26px">
                <span style="width:8px;height:8px;background:var(--brand);border-radius:2px;display:block;margin-bottom:16px" aria-hidden="true"></span>
                <div style="font-size:16.5px;font-weight:600;letter-spacing:-.01em">{{ $item['title'] }}</div>
                <div class="body-muted" style="margin-top:7px;font-size:14px;color:var(--ink-muted)">{{ $item['desc'] }}</div>
            </div>
        @endforeach
    </div>
</section>
@endif
