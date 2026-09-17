{{-- Başlık bölümü: H2/H3 + alt metin --}}
@php($tag = ($s['level'] ?? 'h2') === 'h3' ? 'h3' : 'h2')
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section--tight sec-inner">
    @if (! empty($s['title']))<{{ $tag }} class="{{ $tag === 'h3' ? 'h3' : 'h2' }}" style="max-width:28ch"{!! ofv($s, 'title') !!}>{{ $s['title'] }}</{{ $tag }}>@endif
    @if (! empty($s['lede']))<p class="lede" style="margin:14px 0 0;max-width:60ch"{!! ofv($s, 'lede') !!}>{{ $s['lede'] }}</p>@endif
</section>
