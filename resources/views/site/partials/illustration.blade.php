{{-- Marka illüstrasyonu (faz 53): medya kütüphanesinde görsel yokken tasarlanmış görsel alanı. Kaynak App\Site\Illustrations.
     Kullanım: @include('site.partials.illustration', ['key' => 'hero', 'alt' => '…', 'style' => '…', 'eager' => false]) --}}
@php($ill = \App\Site\Illustrations::meta($key))
<img src="{{ \App\Site\Illustrations::url($key) }}"
     width="{{ $ill[1] }}" height="{{ $ill[2] }}"
     alt="{{ $alt ?? \App\Site\Illustrations::alt($key, $singleLocation->city ?? null) }}"
     loading="{{ ($eager ?? false) ? 'eager' : 'lazy' }}" decoding="async"
     @if ($eager ?? false) fetchpriority="high" @endif
     style="{{ $style ?? 'width:100%;height:auto;display:block' }}">
