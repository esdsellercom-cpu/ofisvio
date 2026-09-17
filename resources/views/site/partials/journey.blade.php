{{--
    PROJEYE UYARLAMA — bu bölüm orijinal tasarımda yoktu.

    Ofisvio'nun sattığı şey bir masa değil, bir şirketin yasal adresidir; o süreç
    backend'de CompanyStatus state machine'i olarak zaten modellenmiş. Müşterinin
    siteden öğrenmek isteyeceği ilk şey "kaç adım, benden ne isteniyor" sorusudur.

    Adımlar App\Support\ActivationJourney'den gelir ve o sınıf enum'a bağlıdır:
    state machine'e yeni bir durum eklenip buraya eşlenmezse test kırılır, site
    sessizce eskimez.
--}}
<section @if ($anchor) id="{{ $anchor }}" @endif class="wrap section">
    <div class="section-head">
        <div style="min-width:0">
            <p class="eyebrow">02 — Nasıl çalışır</p>
            <h2 class="h2" style="max-width:22ch"{!! ofv($s, 'title', 'texts.journey_title') !!}>{{ $s['title'] ?? $texts['journey_title'] }}</h2>
        </div>
        <p class="body-muted" style="margin:0;max-width:34ch;font-size:16px">
            {{ $s['lede'] ?? $texts['journey_lede'] }}
        </p>
    </div>

    <ol class="grid-auto" style="--min:300px;--gap:1px;background:var(--line);border:1px solid var(--line);border-radius:var(--r-lg);overflow:hidden;list-style:none;margin:0;padding:0;counter-reset:step">
        @foreach ($journey as $step)
            <li style="background:var(--surface);padding:24px 22px 26px;display:flex;flex-direction:column;gap:9px">
                <span class="mono" style="font-size:12px;color:var(--brand);letter-spacing:.1em">{{ $step['no'] }}</span>
                <h3 class="h3" style="font-size:18px">{{ $step['title'] }}</h3>
                <p class="body-muted" style="margin:0;font-size:14px;color:var(--ink-muted)">{{ $step['desc'] }}</p>
            </li>
        @endforeach
    </ol>

    <div class="notice" style="margin-top:20px">
        <span class="notice__dot" aria-hidden="true"></span>
        <p style="margin:0;font-size:14.5px;line-height:1.55;color:var(--ink-soft)">
            <strong>Belgeleriniz yalnızca inceleme için açılır.</strong>
            Kimlik ve sicil belgelerinizin içeriğine erişim, gerekçe kaydı tutulan
            ve süresi dolan bir yetkiyle sınırlıdır; ekibimiz belgeyi görmeden de
            başvurunuzun durumunu takip edebilir.
        </p>
    </div>
</section>
