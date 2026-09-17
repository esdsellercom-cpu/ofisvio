{{-- :::faq  title: … / - Soru? | Cevap  (SEO: FAQPage şeması için "## Soru?" başlıkları da kullanılabilir) --}}
@php($f = $data['fields'])
<section class="content-block content-block--faq" style="margin:28px 0">
    @if (($f['title'] ?? '') !== '')<h2 class="h3">{{ $f['title'] }}</h2>@endif
    @foreach ($data['items'] as $item)
        <details class="faq-item" style="border-bottom:1px solid var(--line);padding:10px 0">
            <summary style="cursor:pointer;font-weight:600">{{ $item['title'] }}</summary>
            <p style="margin:8px 0 0">{{ $item['text'] }}</p>
        </details>
    @endforeach
</section>
