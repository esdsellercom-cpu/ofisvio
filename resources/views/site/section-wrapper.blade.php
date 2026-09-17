{{-- Bölüm sarmalayıcı: tasarım değişkenleri (SectionStyle) + cihaz süzgeci; editörde id/tip/kilit/görünürlük işaretleri.
     $section: SiteBuilderService::renderable satırı. --}}
@php($s = $section['settings'])
@php($anchor = $section['anchor'])
@php($sectionMedia = $section['media'] ?? [])
@php($secId = $section['id'] ?? null)
@php($domId = 'sec-'.($secId ?? ('t'.$loop->index)))
<div id="{{ $domId }}" class="site-section {{ $section['style_class'] }}{{ $section['hide_on_mobile'] ? ' hide-mobile' : '' }}{{ $section['hide_on_desktop'] ? ' hide-desktop' : '' }}{{ ofv_editor() && ! $section['is_visible'] ? ' ofv-invisible' : '' }}" data-type="{{ $section['type'] }}"@if ($section['style'] !== '') style="{{ $section['style'] }}"@endif @if (! empty($s['style']['cols'])) data-cols="{{ $s['style']['cols'] }}"@endif @if (! empty($s['style_tablet']['cols'])) data-cols-t="{{ $s['style_tablet']['cols'] }}"@endif @if (! empty($s['style_mobile']['cols'])) data-cols-m="{{ $s['style_mobile']['cols'] }}"@endif @if (ofv_editor()) data-ofv-section="{{ $secId ?? '' }}" data-ofv-type="{{ $section['type'] }}" @if ($section['locked']) data-ofv-locked="1" @endif @endif>
    @if ($section['media_css'] !== '')<style>{!! $section['media_css'] !!}</style>@endif
    @include('site.sections.'.$section['type'])
</div>
