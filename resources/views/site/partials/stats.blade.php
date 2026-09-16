{{-- Rakamlar HomeController'da veritabanından hesaplanır, elle yazılmaz. --}}
<section class="wrap section--tight">
    <div style="border-top:1px solid var(--line);border-bottom:1px solid var(--line);display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
        @foreach ($stats as $stat)
            @continue($stat['value'] === '')
            <div style="padding:26px 22px 26px 0">
                <div style="font-size:38px;font-weight:600;letter-spacing:-.035em;line-height:1">{{ $stat['value'] }}</div>
                <div style="margin-top:8px;font-size:14px;color:var(--ink-muted)">{{ $stat['label'] }}</div>
            </div>
        @endforeach
    </div>
</section>
