@extends($siteLayout)

@section('content')
    <section class="wrap section" style="padding-top:64px;max-width:960px">
        <p class="eyebrow">Site haritası</p>
        <h1 class="h2">{{ $currentWebsite->name }} — tüm sayfalar</h1>
        <div class="grid-auto" style="--min:260px;--gap:28px;margin-top:28px">
            @foreach ($sections as $section)
                <div>
                    <h2 class="h3" style="margin:0 0 10px">{{ $section['label'] }}</h2>
                    <ul class="stack" style="gap:6px;margin:0;padding:0;list-style:none">
                        @foreach ($section['items'] as $item)<li><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></li>@endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>
@endsection
