{{-- Responsive görsel: srcset (GD varyantları + orijinal), sizes, lazy. Kaynak yalnız Media modeli —
     kodda görsel yolu yok. Kullanım: @include('site.partials.picture', ['media' => $m, 'sizes' => '(max-width: 640px) 100vw, 50vw', 'style' => '…', 'eager' => false]) --}}
@php($eager = $eager ?? false)
<img src="{{ $media->urlFor(960) }}"
     srcset="{{ $media->srcset() }}"
     sizes="{{ $sizes ?? '100vw' }}"
     width="{{ $media->width }}" height="{{ $media->height }}"
     alt="{{ $media->alt ?? '' }}"
     @if ($media->title) title="{{ $media->title }}" @endif
     loading="{{ $eager ? 'eager' : 'lazy' }}" decoding="async"
     @if ($eager) fetchpriority="high" @endif
     style="{{ $style ?? 'width:100%;height:auto;display:block' }}"{!! $le ?? '' !!}>
