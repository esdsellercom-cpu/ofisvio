{{-- SSS bölümü: soru | cevap satırları; FAQPage JSON-LD --}}
@php($items = collect((array) ($s['items'] ?? []))->map(fn ($l) => array_map('trim', explode('|', (string) $l, 2)))->filter(fn ($p) => count($p) === 2 && $p[0] !== '' && $p[1] !== '')->values())
@if ($items->isNotEmpty())
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section">
    <h2 class="h2" style="max-width:24ch;margin-bottom:24px">{{ $s['title'] ?? 'Sık sorulanlar' }}</h2>
    <div class="stack" style="gap:10px;max-width:76ch">
        @foreach ($items as [$q, $a])
            <details class="card" style="padding:16px 18px;display:block">
                <summary style="cursor:pointer;font-weight:600">{{ $q }}</summary>
                <p class="body-muted" style="margin:10px 0 0">{{ $a }}</p>
            </details>
        @endforeach
    </div>
    <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $items->map(fn ($p) => ['@type' => 'Question', 'name' => $p[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $p[1]]])->all()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
</section>
@endif