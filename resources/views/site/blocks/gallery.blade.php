{{-- :::gallery  - /gorsel-url | alt metin --}}
<section class="content-block content-block--gallery" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin:28px 0">
    @foreach ($data['items'] as $item)
        @if (str_starts_with($item['title'], '/') || str_starts_with($item['title'], 'https://'))
            <img src="{{ $item['title'] }}" alt="{{ $item['text'] }}" loading="lazy" style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:var(--r-lg)">
        @endif
    @endforeach
</section>
