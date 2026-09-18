{{-- GEO Answer Engine (faz 60b): hizmetin yapılandırılmış cevapları — yalnız dolu bölümler; başlıklar sabit soru kalıbı
     (AI motorları "X nedir?" cevabını başlık altındaki ilk paragraftan alır). $sections: GeoAnswers::sections(), $faq: çiftler. --}}
@if ($sections !== [] || $faq !== [])
    <section class="geo-answers" style="margin-top:44px" id="cevaplar">
        @foreach ($sections as $section)
            <div style="margin-bottom:26px">
                <h2 class="h3" style="margin:0 0 8px">{{ $section['title'] }}</h2>
                @if ($section['type'] === 'list')
                    <ol style="margin:0;padding-left:22px;font-size:15.5px;line-height:1.7">
                        @foreach ($section['value'] as $item)<li>{{ $item }}</li>@endforeach
                    </ol>
                @else
                    @foreach (preg_split('/\r?\n\r?\n/', $section['value']) as $paragraph)
                        <p style="margin:0 0 10px;font-size:15.5px;line-height:1.7;max-width:70ch">{{ trim($paragraph) }}</p>
                    @endforeach
                @endif
            </div>
        @endforeach

        @if ($faq !== [])
            <h2 class="h3" style="margin:0 0 10px">Sık sorulan sorular</h2>
            <div class="stack" style="gap:8px">
                @foreach ($faq as $pair)
                    <details class="panel" style="padding:12px 16px">
                        <summary style="cursor:pointer;font-weight:600">{{ $pair['q'] }}</summary>
                        <p style="margin:8px 0 0;line-height:1.65">{{ $pair['a'] }}</p>
                    </details>
                @endforeach
            </div>
        @endif
    </section>
@endif
