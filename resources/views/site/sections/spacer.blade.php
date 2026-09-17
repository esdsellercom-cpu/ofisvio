{{-- Dikey boşluk --}}
<div @if ($anchor) id="{{ $anchor }}" @endif style="height:{{ max(0, min(400, (int) ($s['height'] ?? 48))) }}px" aria-hidden="true"{!! ofv_editor() ? ' data-ofv-spacer' : '' !!}></div>
