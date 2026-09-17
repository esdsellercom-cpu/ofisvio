{{-- Galeri: medya kütüphanesi görselleri --}}
@php($ids = array_values(array_filter(array_map('intval', (array) ($s['media'] ?? [])))))
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section--tight sec-inner">
    @if (! empty($s['title']))<h2 class="h2" style="max-width:24ch;margin-bottom:20px"{!! ofv($s, 'title') !!}>{{ $s['title'] }}</h2>@endif
    <div class="grid-auto sec-grid" style="--min:200px;--gap:12px"{!! ofv_editor() ? ' data-ofv-gallery' : '' !!}>
        @forelse ($ids as $id)
            @if (isset($sectionMedia[$id]))
                <figure style="margin:0"><img src="{{ $sectionMedia[$id]['url'] }}" alt="{{ $sectionMedia[$id]['alt'] }}" loading="lazy" decoding="async" style="width:100%;aspect-ratio:{{ $s['ratio'] ?? '4/3' }};object-fit:cover;border-radius:var(--sec-radius, 12px);display:block" @if (ofv_editor()) data-ofv-image="media[]" data-ofv-media-id="{{ $id }}" @endif>@if ($sectionMedia[$id]['caption'] !== '')<figcaption class="small muted" style="margin-top:6px">{{ $sectionMedia[$id]['caption'] }}</figcaption>@endif</figure>
            @endif
        @empty
            @if (ofv_editor())<div class="shot" style="aspect-ratio:4/3;border:1px dashed var(--line);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--ink-faint)" data-ofv-image="media[]">Görsel ekleyin</div>@endif
        @endforelse
    </div>
</section>
