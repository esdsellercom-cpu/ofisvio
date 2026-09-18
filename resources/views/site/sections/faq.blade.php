{{-- SSS bölümü: soru | cevap satırları; FAQPage JSON-LD --}}
@php($items = collect((array) ($s['items'] ?? []))->map(fn ($l) => array_map('trim', explode('|', (string) $l, 2)) + [1 => ''])->filter(fn ($p) => ($p[0] !== '' && $p[1] !== '') || ofv_editor()))
@if ($items->isNotEmpty() || ofv_editor())
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section">
    <h2 class="h2" style="max-width:24ch;margin-bottom:24px"{!! ofv($s, 'title') !!}>{{ $s['title'] ?? 'Sık sorulanlar' }}</h2>
    <div class="stack" style="gap:10px;max-width:76ch">
        @foreach ($items as $i => [$q, $a])
            <details class="card" style="padding:16px 18px;display:block" @if (ofv_editor()) open @endif>
                <summary style="cursor:pointer;font-weight:600"><span{!! ofv_item('items', $i, 0) !!}>{{ $q }}</span></summary>
                <p class="body-muted" style="margin:10px 0 0"{!! ofv_item('items', $i, 1) !!}>{{ $a }}</p>
            </details>
        @endforeach
    </div>
    <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $items->filter(fn ($p) => $p[0] !== '' && $p[1] !== '')->values()->map(fn ($p) => ['@type' => 'Question', 'name' => $p[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $p[1]]])->all()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
</section>
@endif