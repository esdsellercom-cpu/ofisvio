{{-- Kolonlar: 2–4 Markdown kolon; kolon sayısı tasarım ayarından (--sec-cols) ya da dolu kolon sayısından --}}
@php($cols = array_values(array_filter([$s['col1'] ?? '', $s['col2'] ?? '', $s['col3'] ?? '', $s['col4'] ?? ''], fn ($c) => trim((string) $c) !== '')))
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section--tight sec-inner">
    @if (! empty($s['title']))<h2 class="h2" style="max-width:26ch;margin-bottom:24px"{!! ofv($s, 'title') !!}>{{ $s['title'] }}</h2>@endif
    <div class="grid-auto sec-grid" style="--min:220px;--gap:28px">
        @foreach ($cols as $i => $col)
            <div class="prose"{!! ofv_editor() ? ' data-ofv-md="col'.($i + 1).'"' : '' !!}>{!! \Illuminate\Support\Str::markdown((string) $col, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
        @endforeach
    </div>
</section>
