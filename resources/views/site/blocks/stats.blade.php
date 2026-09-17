{{-- :::stats  - 120+ | Şube / - %98 | Memnuniyet --}}
<section class="content-block content-block--stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin:28px 0">
    @foreach ($data['items'] as $item)
        <div style="padding:16px;border:1px solid var(--line);border-radius:var(--r-lg);text-align:center">
            <div style="font-size:28px;font-weight:700;color:var(--brand)">{{ $item['title'] }}</div>
            <div class="small muted">{{ $item['text'] }}</div>
        </div>
    @endforeach
</section>
