<footer class="site-footer">
    <div class="wrap grid-auto" style="--min:190px;--gap:40px 32px;padding-block:64px 28px">
        <div style="min-width:0">
            <div style="display:flex;align-items:baseline;gap:9px;margin-bottom:18px">
                <span style="width:13px;height:13px;background:var(--brand-light);border-radius:3px;display:block" aria-hidden="true"></span>
                <span class="brand__name" style="color:var(--dark-ink)">{{ $brand['name'] }}</span>
            </div>
            <p style="margin:0;font-size:14.5px;line-height:1.6;max-width:30ch">{{ $brand['tagline'] }}</p>
            <div class="stack mono" style="margin-top:22px;gap:6px;font-size:13.5px">
                @if ($brand['phone'])<a href="{{ $brand['phone_href'] }}" style="color:var(--dark-ink)">{{ $brand['phone'] }}</a>@endif
                @if ($brand['email'])<a href="mailto:{{ $brand['email'] }}" style="color:var(--dark-ink)">{{ $brand['email'] }}</a>@endif
                @if ($brand['address'])<span>{{ $brand['address'] }}</span>@endif
            </div>
        </div>

        @foreach ($blocks['footer_columns'] as $col)
            <div style="min-width:0">
                <div class="label" style="margin-bottom:16px">{{ $col['title'] }}</div>
                <div class="stack" style="gap:9px;font-size:14.5px">
                    @foreach ($col['items'] as $item)
                        <a href="#">{{ $item }}</a>
                    @endforeach
                </div>
            </div>
        @endforeach

        @isset($regions)
            <div style="min-width:0">
                <div class="label" style="margin-bottom:16px">Bölgeler</div>
                <div class="stack" style="gap:9px;font-size:14.5px">
                    @foreach ($regions->keys() as $regionName)
                        <a href="#lokasyonlar">{{ $regionName }}</a>
                    @endforeach
                </div>
            </div>
        @endisset
    </div>

    <div class="wrap" style="padding-block:22px 40px;border-top:1px solid var(--dark-line);display:flex;flex-wrap:wrap;gap:14px;justify-content:space-between;font-size:13px;color:var(--dark-ink-mute)">
        <span>© {{ date('Y') }} {{ $brand['legal_name'] }}</span>
        <span style="display:flex;gap:18px;flex-wrap:wrap">
            {{-- CMS: yayındaki sayfalar (SiteFooterComposer). Yayında sayfa yoksa bağlantı basılmaz. --}}
            @foreach ($legalPages ?? [] as $page)
                <a href="{{ route('site.page', $page->slug) }}" style="color:var(--dark-ink-mute)">{{ $page->title }}</a>
            @endforeach
        </span>
    </div>
</footer>
