@extends('layouts.panel')

@section('title', 'AI Content Engine — '.$website->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.center', $website) }}">Command Center</a> · {{ $website->name }}</p>
            <h1 class="h2">AI Content Engine</h1>
            <p>Konu keşfi → Brief → AI taslak → Doğruluk kontrolü → SEO → GEO → Kopya denetimi → İnceleme → Onay (dört göz) → Zamanlama → Yayın. Her adım insan tetiklemesiyle; AI çağrısı Integration Gateway üzerinden, sağlayıcı/model/prompt sürümü/token/maliyet iş kaydında. AI yayındaki içeriği asla doğrudan değiştirmez: sonuç yeni taslak ya da çalışma taslağıdır.</p>
        </div>
        <div class="panel-head__actions">
            <span class="badge badge--{{ $available ? 'ok' : 'muted' }}">AI sağlayıcısı: {{ $available ? 'bağlı' : 'bağlı değil (AI_ENABLED + AI_API_KEY)' }}</span>
            <a href="{{ route('panel.seo.ai.prompts', $website) }}" class="btn btn--ghost btn--pill">Prompt Registry</a>
            <a href="{{ route('panel.seo.ai.usage', $website) }}" class="btn btn--ghost btn--pill">Kullanım & maliyet</a>
            <a href="{{ route('panel.seo.refresh', $website) }}" class="btn btn--ghost btn--pill">İçerik yenileme</a>
        </div>
    </div>

    <div class="grid-auto" style="--min:340px;--gap:18px;align-items:start;margin-bottom:18px">
        <div class="panel">
            <p class="eyebrow">Konu keşfi (gerçek sinyaller)</p>
            @if ($topics === [])<p class="body-muted small" style="margin:0">Sinyal yok: Keyword Intelligence'ta hedefsiz kelime, Search Console fırsatı ya da yenileme adayı olunca burada listelenir.</p>@else
                <ul style="margin:0;padding-left:18px;max-height:320px;overflow:auto">
                    @foreach ($topics as $t)<li><strong>{{ $t['topic'] }}</strong> <span class="badge badge--info">{{ $t['source'] }}</span><span class="small muted" style="display:block">{{ $t['detail'] }}</span></li>@endforeach
                </ul>
            @endif
        </div>

        @if ($can['generate'])
            <form method="POST" action="{{ route('panel.seo.ai.store', $website) }}" class="panel stack" style="gap:10px">
                @csrf
                <p class="eyebrow" style="margin:0">Brief (insan girdisi)</p>
                <label class="field"><span class="label">Konu</span><input class="control" type="text" name="topic" value="{{ old('topic') }}" required minlength="3" maxlength="200" placeholder="Konya'da şirket kurarken sanal ofis adresi"></label>
                <div class="grid-auto" style="--min:160px;--gap:8px">
                    <label class="field"><span class="label">Hedef kitle</span><input class="control" type="text" name="audience" value="{{ old('audience') }}" maxlength="300"></label>
                    <label class="field"><span class="label">Arama niyeti</span><select class="control" name="intent"><option value="informational">Bilgi</option><option value="commercial">Karşılaştırma</option><option value="transactional">Satın alma</option><option value="local">Yerel</option></select></label>
                </div>
                <label class="field"><span class="label">Anahtar kelimeler (virgülle; ilki odak)</span><input class="control" type="text" name="keywords" value="{{ old('keywords') }}" maxlength="300"></label>
                <div class="grid-auto" style="--min:160px;--gap:8px">
                    <div><span class="label">İlgili hizmetler</span>@foreach ($services as $s)<label class="checkbox-row small"><input type="checkbox" name="services[]" value="{{ $s->id }}"><span>{{ $s->name }}</span></label>@endforeach</div>
                    <div><span class="label">İlgili lokasyonlar</span>@foreach ($locations as $l)<label class="checkbox-row small"><input type="checkbox" name="locations[]" value="{{ $l->id }}"><span>{{ $l->name }}</span></label>@endforeach</div>
                </div>
                <label class="field"><span class="label">Notlar / taslak ana hatlar</span><textarea class="control" name="notes" maxlength="2000" style="min-height:80px">{{ old('notes') }}</textarea></label>
                <label class="field"><span class="label">Yenileme işi ise kaynak içerik kimliği (boş: yeni makale)</span><input class="control mono" type="number" name="source_content_id" value="{{ old('source_content_id', request()->query('kaynak')) }}" min="1"></label>
                <div><button type="submit" class="btn btn--brand">İş oluştur</button> <span class="small muted">Taslak üretimi sağlayıcı bağlıyken çalışır; diğer denetimler her zaman çalışır.</span></div>
            </form>
        @endif
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>İş</th><th>Tür</th><th>Aşama</th><th>Model · prompt</th><th>Token</th><th>Sonuç</th><th></th></tr></thead>
            <tbody>
                @forelse ($jobs as $j)
                    <tr>
                        <td><strong>{{ $j->topic }}</strong><span class="small muted" style="display:block">#{{ $j->id }} · {{ $j->creator?->name }} · {{ $j->created_at->format('d.m.Y H:i') }}</span></td>
                        <td class="small">{{ $j->kind === 'refresh' ? 'Yenileme' : 'Makale' }}</td>
                        <td><span class="badge badge--{{ $j->stage === 'done' ? 'ok' : ($j->stage === 'rejected' ? 'danger' : 'info') }}">{{ $stages[$j->stage] ?? $j->stage }}</span>@if ($j->error)<span class="small" style="display:block;color:var(--danger)">{{ $j->error }}</span>@endif</td>
                        <td class="small mono">{{ $j->model ?? '—' }}@if ($j->prompt_key) · {{ $j->prompt_key }}@{{ $j->prompt_version }}@endif</td>
                        <td class="small mono">{{ $j->input_tokens }}+{{ $j->output_tokens }}@if ($j->cost !== null) · {{ $j->cost }} {{ $j->cost_currency }}@endif</td>
                        <td class="small">@if ($j->content)<a href="{{ route('panel.content.show', $j->content) }}">{{ $j->content->title }}</a>@elseif ($j->source)kaynak: {{ $j->source->title }}@else —@endif</td>
                        <td><a href="{{ route('panel.seo.ai.show', [$website, $j]) }}" class="btn btn--ghost btn--pill">Aç</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="body-muted">Henüz iş yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
