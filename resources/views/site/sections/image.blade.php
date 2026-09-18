{{-- Görsel bölümü: medya kütüphanesinden tek görsel; caption + bağlantı; sığdırma/oran/genişlik --}}
@php($img = ! empty($s['media']) ? ($sectionMedia[(int) $s['media']] ?? null) : null)
@php($ratio = ($s['ratio'] ?? 'auto') !== 'auto' ? 'aspect-ratio:'.$s['ratio'].';' : '')
@php($width = max(10, min(100, (int) (($s['width'] ?? '') ?: 100))))
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section--tight sec-inner">
    <figure class="content-img" style="margin:0 auto;width:{{ $width }}%">
        @if ($img)
            @if (! empty($s['link']))<a href="{{ $s['link'] }}" @if (str_starts_with($s['link'], 'http')) target="_blank" rel="noopener" @endif>@endif
            <img src="{{ $img['url'] }}" alt="{{ $img['alt'] }}" loading="lazy" decoding="async" style="width:100%;{{ $ratio }}object-fit:{{ ($s['fit'] ?? 'cover') === 'contain' ? 'contain' : 'cover' }};border-radius:var(--sec-radius, var(--r-lg));display:block" @if (ofv_editor()) data-ofv-image="media" @endif{!! ofv_le('section', (int) ($secId ?? 0), 'media', null, 'Görsel bölümü', (int) $s['media'], (string) ($s['caption'] ?? '')) !!}>
            @if (! empty($s['link']))</a>@endif
        @else
            <div class="shot" style="{{ $ratio ?: 'aspect-ratio:16/9;' }}border-radius:var(--r-lg);border:1px dashed var(--line);display:flex;align-items:center;justify-content:center;color:var(--ink-faint)" @if (ofv_editor()) data-ofv-image="media" @endif{!! ofv_le('section', (int) ($secId ?? 0), 'media', null, 'Görsel bölümü (boş)', null, (string) ($s['caption'] ?? '')) !!}>Görsel seçilmedi</div>
        @endif
        @if (! empty($s['caption']))<figcaption{!! ofv($s, 'caption') !!}>{{ $s['caption'] }}</figcaption>@endif
    </figure>
</section>
