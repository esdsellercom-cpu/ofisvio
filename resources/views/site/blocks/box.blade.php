{{-- :::box  title: … / text: … / tone: info|warn|success --}}
@php($f = $data['fields'])
@php($tone = in_array($f['tone'] ?? '', ['warn', 'success'], true) ? $f['tone'] : 'info')
<aside class="content-block content-block--box" style="border-left:4px solid {{ $tone === 'warn' ? '#c98a1a' : ($tone === 'success' ? '#2f7d4f' : 'var(--brand)') }};background:var(--surface-warm, #f6f4ef);padding:14px 18px;border-radius:var(--r-lg);margin:22px 0">
    @if (($f['title'] ?? '') !== '')<strong style="display:block;margin-bottom:4px">{{ $f['title'] }}</strong>@endif
    @if (($f['text'] ?? '') !== '')<span>{{ $f['text'] }}</span>@endif
    @foreach ($data['items'] as $item)<div>• {{ $item['title'] }}@if ($item['text'] !== '') — {{ $item['text'] }}@endif</div>@endforeach
</aside>
