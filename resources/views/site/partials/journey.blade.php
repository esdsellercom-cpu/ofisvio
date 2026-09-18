{{--
    Nasıl çalışır (faz 57: editörde tam düzenlenebilir).

    Adımlar bölüm ayarından gelir (steps: "İkon | Başlık | Açıklama" satırları; editörde + Adım ekle / Sil / sürükle-bırak,
    başlık ve açıklama satır içi düzenlenir). Ayar yoksa varsayılan adımlar App\Support\ActivationJourney'den — o sınıf
    CompanyStatus durum makinesine bağlıdır (state machine'e yeni durum eklenip eşlenmezse test kırılır). Adım görselleri
    isteğe bağlı (images: medya kütüphanesi, sırayla); bilgi kutusu (note) ve CTA da ayardan.
--}}
@php($d = \App\Site\SectionLibrary::journeyDefaults())
@php($stepLines = array_values(array_filter(array_map('strval', (array) ($s['steps'] ?? $d['steps'])), fn ($l) => trim($l) !== '')))
@php($steps = collect($stepLines)->map(fn ($l) => array_map('trim', explode('|', $l, 3)))->values())
@php($images = array_values(array_filter(array_map('intval', (array) ($s['images'] ?? [])))))
@php($note = (string) ($s['note'] ?? '')) {{-- varsayılan metin ilk açılışta/migrasyonla ayara yazılır; boş = gizli --}}
@php($cta = app(\App\Services\SiteBuilderService::class)->cta($s['cta'] ?? null, $currentWebsite))
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section">
    <div class="section-head">
        <div style="min-width:0">
            <p class="eyebrow">02 — Nasıl çalışır</p>
            <h2 class="h2" style="max-width:22ch"{!! ofv($s, 'title', 'texts.journey_title') !!}>{{ $s['title'] ?? $texts['journey_title'] }}</h2>
        </div>
        <p class="body-muted" style="margin:0;max-width:34ch;font-size:16px"{!! ofv($s, 'lede', 'texts.journey_lede') !!}>{{ $s['lede'] ?? $texts['journey_lede'] }}</p>
    </div>

    <ol class="grid-auto" style="--min:300px;--gap:1px;background:var(--line);border:1px solid var(--line);border-radius:var(--r-lg);overflow:hidden;list-style:none;margin:0;padding:0" data-journey-steps>
        @foreach ($steps as $i => $p)
            @php($mediaId = $images[$i] ?? null)
            @php($media = $mediaId !== null ? ($sectionMedia[$mediaId] ?? null) : null)
            <li style="background:var(--surface);padding:24px 22px 26px;display:flex;flex-direction:column;gap:9px" data-journey-step>
                @if ($media)
                    <img src="{{ $media['url'] }}" alt="{{ $media['alt'] !== '' ? $media['alt'] : ($p[1] ?? '') }}" loading="lazy" decoding="async" style="width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:var(--r-md);display:block;margin-bottom:6px"{!! ofv_editor() ? ' data-ofv-image="images[]" data-ofv-media-id="'.$mediaId.'"' : '' !!}>
                @elseif (ofv_editor())
                    <div class="shot" style="aspect-ratio:16/10;border:1px dashed var(--line);border-radius:var(--r-md);display:flex;align-items:center;justify-content:center;color:var(--ink-faint);font-size:13px" data-ofv-image="images[]">Adım görseli ekle</div>
                @endif
                <div style="display:flex;align-items:center;gap:10px">
                    <span class="mono" style="font-size:12px;color:var(--brand);letter-spacing:.1em">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                    @if (($p[0] ?? '') !== '' || ofv_editor())<span style="font-size:20px;line-height:1" aria-hidden="true"{!! ofv_item('steps', $i, 0) !!}>{{ $p[0] ?? '' }}</span>@endif
                </div>
                <h3 class="h3" style="font-size:18px"{!! ofv_item('steps', $i, 1) !!}>{{ $p[1] ?? '' }}</h3>
                <p class="body-muted" style="margin:0;font-size:14px;color:var(--ink-muted)"{!! ofv_item('steps', $i, 2) !!}>{{ $p[2] ?? '' }}</p>
            </li>
        @endforeach
    </ol>

    @if (trim($note) !== '' || ofv_editor())
        <div class="notice" style="margin-top:20px" @if (trim($note) === '') hidden @endif>
            <span class="notice__dot" aria-hidden="true"></span>
            <p style="margin:0;font-size:14.5px;line-height:1.55;color:var(--ink-soft)"{!! ofv($s, 'note') !!}>{{ $note }}</p>
        </div>
    @endif

    @if ($cta)
        <p style="margin:20px 0 0"><a href="{{ $cta['href'] }}" class="btn btn--brand btn--pill" @if ($cta['external']) target="_blank" rel="noopener" @endif>{{ $cta['label'] }}</a></p>
    @endif
</section>
