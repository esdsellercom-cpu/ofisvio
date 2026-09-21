@extends('layouts.panel')

@section('title', $service ? 'Hizmet — '.$service->name : 'Yeni hizmet')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.services.index') }}">Hizmetler</a></p>
            <h1 class="h2">{{ $service ? $service->name : 'Yeni hizmet' }}</h1>
        </div>
    </div>

    <form method="POST" action="{{ $service ? route('panel.services.update', $service) : route('panel.services.store') }}" class="panel stack" style="gap:14px;max-width:820px">
        @csrf @if ($service) @method('PUT') @endif
        @error('name')<div class="notice notice--error" role="alert"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror
        <div class="grid-auto" style="--min:220px;--gap:12px">
            <label class="field"><span class="label">Ad</span><input class="control" type="text" name="name" value="{{ old('name', $service?->name) }}" required minlength="2" maxlength="80"></label>
            <label class="field"><span class="label">Fiyat metni (boş: gösterilmez)</span><input class="control mono" type="text" name="price_text" value="{{ old('price_text', $service?->price_text) }}" maxlength="60" placeholder="örn. {{ money_symbol() }}790/ay başlayan"></label>
            <label class="field"><span class="label">Rezervasyon türü (odalarla bağ)</span>
                <select class="control" name="booking_kind"><option value="">— rezervasyonsuz —</option>@foreach ($kinds as $k => $l)<option value="{{ $k }}" @selected(old('booking_kind', $service?->booking_kind) === $k)>{{ $l }}</option>@endforeach</select>
            </label>
            <label class="field"><span class="label">Sıra</span><input class="control mono" type="number" name="sort_order" value="{{ old('sort_order', $service?->sort_order ?? 0) }}" min="0" max="999"></label>
        </div>
        <label class="field"><span class="label">Özet (kart metni)</span><textarea class="control" name="summary" maxlength="300" style="min-height:70px">{{ old('summary', $service?->summary) }}</textarea></label>
        <label class="field"><span class="label">Açıklama (Markdown, hizmet sayfası)</span><textarea class="control mono" name="description" style="min-height:200px;font-size:14px">{{ old('description', $service?->description) }}</textarea></label>
        <label class="field"><span class="label">Kapak görseli (medya kütüphanesi)</span>
            <select class="control" name="cover_media_id"><option value="">— yok —</option>@foreach ($mediaOptions as $m)<option value="{{ $m->id }}" @selected((string) old('cover_media_id', $service?->cover_media_id) === (string) $m->id)>{{ $m->original_name }} ({{ $m->width }}×{{ $m->height }})</option>@endforeach</select>
        </label>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
            <label class="checkbox-row"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $service?->is_active ?? true))><span>Aktif (vitrinde)</span></label>
            <label class="checkbox-row"><input type="checkbox" name="is_flagship" value="1" @checked(old('is_flagship', $service?->is_flagship ?? false))><span>Amiral ürün</span></label>
        </div>
        @php($answers = old('answers', $service?->answers ?? []))
        <details class="panel" style="margin:0" @if ($service && \App\Seo\GeoAnswers::filled($service->answers) > 0) open @endif>
            <summary style="cursor:pointer;font-weight:600">GEO cevap motoru — yapılandırılmış cevaplar @if ($service)<span class="badge badge--{{ \App\Seo\GeoAnswers::filled($service->answers) >= 6 ? 'ok' : 'warn' }}" style="margin-left:8px">{{ \App\Seo\GeoAnswers::filled($service->answers) }}/13 dolu</span>@endif</summary>
            <p class="small muted" style="margin:8px 0 12px">Üretken arama motorları (ChatGPT, Perplexity, Gemini) "X nedir, kimler için, hangi belgeler gerekir" sorularını bu bölümlerden alıntılar. Hizmet sayfasında başlıklı bölümler, SSS şeması ve llms.txt buradan beslenir. Yalnız gerçek bilgi; uydurma fiyat/rakam yazmayın.</p>
            <div class="grid-auto" style="--min:300px;--gap:12px">
                @foreach ($answerFields as $key => [$label, $type, $help])
                    <label class="field"><span class="label">{{ $label }}</span>
                        <textarea class="control" name="answers[{{ $key }}]" style="min-height:{{ $type === 'list' ? 96 : 84 }}px" placeholder="{{ $help }}">{{ is_array($answers[$key] ?? null) ? implode("\n", $answers[$key]) : ($answers[$key] ?? '') }}</textarea>
                        <span class="small muted">{{ $type === 'list' ? 'Satır başına bir madde.' : $help }}</span>
                    </label>
                @endforeach
            </div>
            <p class="label" style="margin:16px 0 8px">Sık sorulan sorular (≥ 2 satır → FAQPage şeması)</p>
            <div class="stack" style="gap:8px">
                @for ($i = 0; $i < $faqRows; $i++)
                    <div class="grid-auto" style="--min:240px;--gap:8px">
                        <input class="control" type="text" name="answers[faq][{{ $i }}][q]" maxlength="200" placeholder="Soru {{ $i + 1 }}" value="{{ $answers['faq'][$i]['q'] ?? '' }}">
                        <input class="control" type="text" name="answers[faq][{{ $i }}][a]" maxlength="1000" placeholder="Cevap" value="{{ $answers['faq'][$i]['a'] ?? '' }}">
                    </div>
                @endfor
            </div>
            <div class="grid-auto" style="--min:260px;--gap:12px;margin-top:16px">
                <div><p class="label" style="margin:0 0 6px">İlgili hizmetler</p>
                    @foreach ($serviceOptions as $opt)<label class="checkbox-row"><input type="checkbox" name="answers[related_services][]" value="{{ $opt->id }}" @checked(in_array($opt->id, array_map('intval', (array) ($answers['related_services'] ?? [])), true))><span>{{ $opt->name }}</span></label>@endforeach
                </div>
                <div><p class="label" style="margin:0 0 6px">İlgili lokasyonlar</p>
                    @forelse ($locationOptions as $opt)<label class="checkbox-row"><input type="checkbox" name="answers[related_locations][]" value="{{ $opt->id }}" @checked(in_array($opt->id, array_map('intval', (array) ($answers['related_locations'] ?? [])), true))><span>{{ $opt->name }} <span class="small muted">{{ $opt->city }}@if (! $opt->is_published) · yayında değil @endif</span></span></label>@empty<span class="small muted">Şube yok.</span>@endforelse
                </div>
            </div>
        </details>
        @if ($service)
            <p class="small muted" style="margin:0">Sunan lokasyonlar: {{ $service->locations->pluck('name')->implode(', ') ?: '—' }} — bağ, lokasyon künyesinden yönetilir.</p>
        @endif
        <div style="display:flex;gap:10px"><button type="submit" class="btn btn--brand">Kaydet</button><a href="{{ route('panel.services.index') }}" class="btn btn--ghost">Vazgeç</a></div>
    </form>
@endsection