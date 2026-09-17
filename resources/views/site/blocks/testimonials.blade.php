{{-- :::testimonials  - Ad, Şirket | Yorum --}}
<section class="content-block content-block--testimonials" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin:28px 0">
    @foreach ($data['items'] as $item)
        <blockquote style="margin:0;padding:16px 18px;border:1px solid var(--line);border-radius:var(--r-lg);background:var(--surface, #fff)">
            <p style="margin:0 0 10px">“{{ $item['text'] }}”</p>
            <footer class="small muted">— {{ $item['title'] }}</footer>
        </blockquote>
    @endforeach
</section>
