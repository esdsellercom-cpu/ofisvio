@extends('layouts.panel')

@section('title', $page ? 'Programatik sayfa — '.$page->title : 'Yeni programatik sayfa')

@section('content')
    @php($faq = old('faq', $page?->faq ?? []))
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.landing.index', $website) }}">Programatik SEO</a> · {{ $website->name }}</p>
            <h1 class="h2">{{ $page ? $page->title : 'Yeni hizmet × şehir sayfası' }}</h1>
            @if ($page)<p class="mono small" style="margin:6px 0 0">{{ $page->path() }} · {{ $page->status === 'published' ? 'yayında' : 'taslak' }}</p>@endif
        </div>
        @if ($page && $page->quality)
            <div class="panel-head__actions">
                <span class="badge badge--{{ $page->quality['ok'] ? 'ok' : 'warn' }}">Kalite {{ $page->quality['score'] }}/100 · benzerlik %{{ (int) round(($page->quality['similarity'] ?? 0) * 100) }} · {{ $page->quality['intro_chars'] ?? 0 }} karakter</span>
            </div>
        @endif
    </div>

    @if ($page && $page->quality && ! $page->quality['ok'])
        <div class="note w" style="margin-bottom:16px"><strong>Yayınlanamaz:</strong> {{ implode(' · ', $page->quality['issues']) }}</div>
    @endif

    <form method="POST" action="{{ $page ? route('panel.seo.landing.update', [$website, $page]) : route('panel.seo.landing.store', $website) }}" class="panel stack" style="gap:14px;max-width:900px">
        @csrf @if ($page) @method('PUT') @endif
        <div class="grid-auto" style="--min:240px;--gap:12px">
            <label class="field"><span class="label">Hizmet</span>
                <select class="control" name="service_id" required @disabled($page !== null)>
                    <option value="">— seçin —</option>
                    @foreach ($services as $s)<option value="{{ $s->id }}" @selected((int) old('service_id', $page?->service_id) === $s->id)>{{ $s->name }}@if (! $s->is_active) (pasif)@endif</option>@endforeach
                </select>
            </label>
            <label class="field"><span class="label">Şube (şehir sayfanın adresine girer)</span>
                <select class="control" name="location_id" required @disabled($page !== null)>
                    <option value="">— seçin —</option>
                    @foreach ($locations as $l)<option value="{{ $l->id }}" @selected((int) old('location_id', $page?->location_id) === $l->id)>{{ $l->name }} — {{ $l->city }}@if (! $l->is_published) (yayında değil)@endif</option>@endforeach
                </select>
            </label>
        </div>
        <p class="small muted" style="margin:0">Şablon değişkenleri (yalnız veritabanındaki değerlerle dolar): <span class="mono">{{ implode(' ', $variables) }}</span>. Örn. "{sehir_da} {hizmet}" → "Konya'da Sanal Ofis".</p>
        <div class="grid-auto" style="--min:240px;--gap:12px">
            <label class="field"><span class="label">Başlık (H1 + title; boş: "{hizmet} {sehir}")</span><input class="control" type="text" name="title" value="{{ old('title', $page?->title) }}" maxlength="120" placeholder="{sehir_da} {hizmet}"></label>
            <label class="field"><span class="label">Meta açıklama (≤ 200)</span><input class="control" type="text" name="meta_description" value="{{ old('meta_description', $page?->meta_description) }}" maxlength="200"></label>
        </div>
        <label class="field"><span class="label">Benzersiz giriş metni (≥ {{ $minIntro }} karakter; bu şehre özgü gerçek bilgi: ulaşım, bölge, kimler kullanıyor, süreç)</span>
            <textarea class="control" name="intro" required minlength="{{ $minIntro }}" style="min-height:180px" placeholder="{sehir_da} {hizmet} arayan girişimciler için {sube} …">{{ old('intro', $page?->intro) }}</textarea>
            <span class="small muted">Hizmet açıklamasını kopyalamayın; benzerlik sınırı aşılırsa yayın kapısı geçmez. Uydurma rakam/adres yazmayın.</span>
        </label>
        <label class="field"><span class="label">Gövde (Markdown, isteğe bağlı)</span><textarea class="control mono" name="body" style="min-height:160px;font-size:14px">{{ old('body', $page?->body) }}</textarea></label>
        <div>
            <p class="label" style="margin:0 0 8px">Sayfaya özgü SSS (≥ 2 satır → FAQPage; hizmetin genel SSS'i ayrıca hizmet sayfasındadır)</p>
            <div class="stack" style="gap:8px">
                @for ($i = 0; $i < 6; $i++)
                    <div class="grid-auto" style="--min:240px;--gap:8px">
                        <input class="control" type="text" name="faq[{{ $i }}][q]" maxlength="200" placeholder="Soru {{ $i + 1 }}" value="{{ $faq[$i]['q'] ?? '' }}">
                        <input class="control" type="text" name="faq[{{ $i }}][a]" maxlength="1000" placeholder="Cevap" value="{{ $faq[$i]['a'] ?? '' }}">
                    </div>
                @endfor
            </div>
        </div>
        <label class="checkbox-row"><input type="checkbox" name="is_indexable" value="1" @checked(old('is_indexable', $page?->is_indexable ?? true))><span>İndekslenebilir (sitemap'e girer; kapalıysa noindex,follow)</span></label>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button type="submit" class="btn btn--brand">{{ $page ? 'Kaydet ve kaliteyi yeniden denetle' : 'Taslak oluştur' }}</button>
            <a href="{{ route('panel.seo.landing.index', $website) }}" class="btn btn--ghost">Listeye dön</a>
            @if ($page && $page->status === 'published')<a href="{{ $website->baseUrl().$page->path() }}" class="btn btn--ghost" target="_blank" rel="noopener">Sayfayı aç ↗</a>@endif
        </div>
    </form>
@endsection
