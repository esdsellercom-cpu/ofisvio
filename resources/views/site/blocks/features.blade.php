{{-- :::features  title: … / - Başlık | Açıklama | /adres --}}
@php($f = $data['fields'])
<section class="content-block content-block--features" style="margin:28px 0">
    @if (($f['title'] ?? '') !== '')<h2 class="h3">{{ $f['title'] }}</h2>@endif
    <div class="grid-auto" style="--min:220px;--gap:14px">
        @foreach ($data['items'] as $item)
            <div class="card"><div class="card__body">
                <strong>{{ $item['title'] }}</strong>
                @if ($item['text'] !== '')<p class="small" style="margin:6px 0 0">{{ $item['text'] }}</p>@endif
                @if ($item['link'] !== '')<a href="{{ $item['link'] }}" class="small" style="display:inline-block;margin-top:8px">Detay →</a>@endif
            </div></div>
        @endforeach
    </div>
</section>
