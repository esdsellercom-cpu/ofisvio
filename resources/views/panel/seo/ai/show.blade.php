@extends('layouts.panel')

@section('title', 'AI iş #'.$job->id.' — '.$job->topic)

@section('content')
    @php($d = $job->draft ?? [])
    @php($c = $job->checks ?? [])
    @php($idx = $job->stageIndex())
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.ai.index', $website) }}">AI Content Engine</a> · iş #{{ $job->id }} · {{ $job->kind === 'refresh' ? 'yenileme' : 'makale' }}</p>
            <h1 class="h2">{{ $job->topic }}</h1>
            @if ($job->source)<p class="small muted" style="margin:6px 0 0">Kaynak içerik: <a href="{{ route('panel.content.show', $job->source) }}">{{ $job->source->title }}</a> — yayındaki metin değişmez; sonuç çalışma taslağı olur.</p>@endif
        </div>
        <div class="panel-head__actions">
            <span class="badge badge--{{ $job->stage === 'done' ? 'ok' : ($job->stage === 'rejected' ? 'danger' : 'info') }}">{{ $stages[$job->stage] }}</span>
            @if ($job->content)<a href="{{ route('panel.content.show', $job->content) }}" class="btn btn--brand btn--pill">İçeriği aç</a>@endif
        </div>
    </div>

    <div class="panel" style="margin-bottom:18px;display:flex;flex-wrap:wrap;gap:6px">
        @foreach ($order as $i => $stage)
            <span class="badge badge--{{ $i < $idx || $job->stage === 'done' ? 'ok' : ($i === $idx ? 'info' : 'muted') }}">{{ $i + 1 }}. {{ $stages[$stage] }}</span>
        @endforeach
        @if ($job->stage === 'rejected')<span class="badge badge--danger">Reddedildi</span>@endif
    </div>

    @if (in_array($job->stage, ['draft', 'fact_check', 'seo', 'geo', 'duplicate'], true) && $can['generate'])
        <form method="POST" action="{{ route('panel.seo.ai.step', [$website, $job]) }}" class="panel" style="margin-bottom:18px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            @csrf
            <div><strong>Sıradaki adım: {{ $stages[$job->stage] }}</strong><span class="small muted" style="display:block">{{ match ($job->stage) { 'draft' => $available ? 'AI sağlayıcısına brief + gerçek marka/hizmet/lokasyon bilgisiyle prompt gönderilir.' : 'AI sağlayıcısı bağlı değil — bu adım çalışmaz (AI_ENABLED + AI_API_KEY).', 'fact_check' => 'Rakam/para/yıl/telefon/mutlak ifade/yasal atıf iddiaları listelenir; sağlayıcı varsa AI iddia çıkarımı eklenir.', 'seo' => 'İçerik stüdyosuyla aynı deterministik SEO analizi.', 'geo' => 'GEO önerileri, SSS sayısı, varlık geçişleri.', default => 'Yayındaki içeriklerle benzerlik; ≥ %60 taslağa geri döner.' } }}</span></div>
            <button type="submit" class="btn btn--brand" @disabled($job->stage === 'draft' && ! $available)>Adımı çalıştır</button>
        </form>
    @endif

    <div class="grid-auto" style="--min:340px;--gap:18px;align-items:start">
        <div class="stack" style="gap:18px">
            <div class="panel">
                <p class="eyebrow">Brief</p>
                <dl class="dl">
                    <dt>Kitle</dt><dd>{{ $job->brief['audience'] ?: '—' }}</dd>
                    <dt>Niyet</dt><dd>{{ $job->brief['intent'] ?: '—' }}</dd>
                    <dt>Anahtar kelimeler</dt><dd>{{ $job->brief['keywords'] ?: '—' }}</dd>
                    <dt>Hizmet / lokasyon</dt><dd class="mono small">{{ implode(',', $job->brief['services'] ?? []) ?: '—' }} / {{ implode(',', $job->brief['locations'] ?? []) ?: '—' }}</dd>
                    <dt>Notlar</dt><dd class="small">{{ $job->brief['notes'] ?: '—' }}</dd>
                </dl>
            </div>

            @if ($d !== [])
                <div class="panel">
                    <p class="eyebrow">Taslak — {{ $job->model }} · {{ $job->prompt_key }}@{{ $job->prompt_version }} · {{ $job->input_tokens }}+{{ $job->output_tokens }} token @if ($job->cost !== null)· {{ $job->cost }} {{ $job->cost_currency }}@endif</p>
                    @if ($job->stage === 'review' && $can['review'])
                        <form method="POST" action="{{ route('panel.seo.ai.review', [$website, $job]) }}" class="stack" style="gap:8px">
                            @csrf
                            <label class="field"><span class="label">Başlık</span><input class="control" type="text" name="title" value="{{ $d['title'] ?? '' }}" maxlength="200"></label>
                            <label class="field"><span class="label">Özet</span><textarea class="control" name="excerpt" maxlength="300" style="min-height:60px">{{ $d['excerpt'] ?? '' }}</textarea></label>
                            <label class="field"><span class="label">Gövde (Markdown)</span><textarea class="control mono" name="body" style="min-height:360px;font-size:13px">{{ $d['body'] ?? '' }}</textarea></label>
                            <div class="grid-auto" style="--min:200px;--gap:8px">
                                <label class="field"><span class="label">Meta başlık</span><input class="control" type="text" name="meta_title" value="{{ $d['meta_title'] ?? '' }}" maxlength="70"></label>
                                <label class="field"><span class="label">Meta açıklama</span><input class="control" type="text" name="meta_description" value="{{ $d['meta_description'] ?? '' }}" maxlength="170"></label>
                            </div>
                            <label class="field"><span class="label">İnceleme notu</span><input class="control" type="text" name="note" maxlength="300"></label>
                            <div style="display:flex;gap:8px;flex-wrap:wrap"><button type="submit" name="decision" value="approve_review" class="btn btn--brand">İncelemeyi tamamla → onaya gönder</button><button type="submit" name="decision" value="reject" class="btn btn--ghost" style="color:var(--danger)">Reddet</button></div>
                        </form>
                    @else
                        <h2 class="h3" style="margin:0 0 6px">{{ $d['title'] ?? '' }}</h2>
                        <p class="small muted" style="margin:0 0 10px">{{ $d['excerpt'] ?? '' }}</p>
                        <pre class="mono small" style="white-space:pre-wrap;word-break:break-word;max-height:420px;overflow:auto;background:var(--surface-2);padding:12px;border-radius:var(--r-sm);margin:0">{{ $d['body'] ?? '' }}</pre>
                        @if (! empty($d['faq']))<p class="small muted" style="margin:10px 0 0">SSS: {{ count($d['faq']) }} soru · Etiketler: {{ implode(', ', $d['tags'] ?? []) ?: '—' }}</p>@endif
                        @if (! empty($d['change_summary']))<ul class="small" style="margin:8px 0 0;padding-left:18px">@foreach ($d['change_summary'] as $line)<li>{{ $line }}</li>@endforeach</ul>@endif
                    @endif
                </div>
            @endif
        </div>

        <div class="stack" style="gap:18px">
            @if (isset($c['fact']))
                <div class="panel">
                    <p class="eyebrow">Doğruluk kontrolü — {{ count($c['fact']['claims']) }} iddia, {{ $c['fact']['high'] }} yüksek risk</p>
                    @if ($c['fact']['claims'] === [])<span class="badge badge--ok">Doğrulanacak iddia bulunmadı</span>@else
                        <ul class="small" style="margin:0;padding-left:18px;max-height:260px;overflow:auto">@foreach ($c['fact']['claims'] as $claim)<li><span class="badge badge--{{ $claim['risk'] === 'high' ? 'danger' : ($claim['risk'] === 'medium' ? 'warn' : 'muted') }}">{{ $claim['type'] }}</span> {{ $claim['claim'] }} <span class="muted">({{ $claim['source'] }}{{ isset($claim['why']) && $claim['why'] !== '' ? ' — '.$claim['why'] : '' }})</span></li>@endforeach</ul>
                    @endif
                </div>
            @endif
            @if (isset($c['seo']))
                <div class="panel">
                    <p class="eyebrow">SEO — skor {{ $c['seo']['score'] }}</p>
                    <ul class="small" style="margin:0;padding-left:18px">@foreach ($c['seo']['checks'] as $check)<li><span class="badge badge--{{ $check['ok'] ? 'ok' : ($check['level'] === 'error' ? 'danger' : 'warn') }}">{{ $check['ok'] ? '✓' : '!' }}</span> {{ $check['message'] }}</li>@endforeach</ul>
                </div>
            @endif
            @if (isset($c['geo']))
                <div class="panel">
                    <p class="eyebrow">GEO — {{ $c['geo']['ok'] ? 'yeterli' : 'eksik' }}</p>
                    <p class="small" style="margin:0 0 6px">SSS: {{ $c['geo']['faq_count'] }} · Varlık geçişleri: {{ implode(', ', $c['geo']['entities_mentioned']) ?: 'yok' }}</p>
                    @foreach ($c['geo']['suggestions'] as $k => $v)<p class="small muted" style="margin:0 0 4px"><strong>{{ $k }}:</strong> {{ is_array($v) ? implode(' · ', array_map('strval', $v)) : $v }}</p>@endforeach
                </div>
            @endif
            @if (isset($c['duplicate']))
                <div class="panel">
                    <p class="eyebrow">Kopya denetimi</p>
                    <span class="badge badge--{{ $c['duplicate']['blocked'] ? 'danger' : ($c['duplicate']['warn'] ? 'warn' : 'ok') }}">En yüksek benzerlik %{{ (int) round($c['duplicate']['ratio'] * 100) }}</span>
                    @if ($c['duplicate']['title'])<span class="small muted"> — {{ $c['duplicate']['title'] }} ({{ $c['duplicate']['path'] }})</span>@endif
                </div>
            @endif

            @if ($job->stage === 'approval')
                <div class="panel">
                    <p class="eyebrow">Onay (dört göz — inceleyenden farklı kullanıcı)</p>
                    @if ($can['approve'])
                        <form method="POST" action="{{ route('panel.seo.ai.approve', [$website, $job]) }}" class="stack" style="gap:8px">
                            @csrf
                            @if ((int) ($c['fact']['high'] ?? 0) > 0)<label class="checkbox-row"><input type="checkbox" name="accept_risks" value="1"><span>Yüksek riskli iddiaları doğruladım / düzelttim</span></label>@endif
                            <div><button type="submit" class="btn btn--brand">Onayla</button></div>
                        </form>
                    @else<p class="small muted" style="margin:0">Onay için ai_content.approve gerekir.</p>@endif
                </div>
            @endif

            @if (in_array($job->stage, ['schedule', 'publish'], true))
                <div class="panel">
                    <p class="eyebrow">Zamanlama & yayın</p>
                    @if ($can['publish'])
                        <form method="POST" action="{{ route('panel.seo.ai.publish', [$website, $job]) }}" class="stack" style="gap:8px">
                            @csrf
                            @if ($job->kind === 'refresh')
                                <p class="small muted" style="margin:0">Yenileme: kaynak içeriğin çalışma taslağı oluşturulur; yayındaki metin siz birleştirene kadar değişmez.</p>
                            @else
                                <label class="field"><span class="label">Zamanla (boş = hemen)</span><input class="control" type="datetime-local" name="scheduled_for"></label>
                                <label class="checkbox-row"><input type="checkbox" name="publish_now" value="1"><span>Zamanlanmadıysa hemen yayınla (content.publish gerekir; aksi halde taslak kalır)</span></label>
                            @endif
                            <div><button type="submit" class="btn btn--brand">CMS'e aktar</button></div>
                        </form>
                    @else<p class="small muted" style="margin:0">Yayın için ai_content.publish gerekir.</p>@endif
                </div>
            @endif

            <div class="panel">
                <p class="eyebrow">Geçmiş</p>
                <ul class="small" style="margin:0;padding-left:18px">@foreach ($job->history ?? [] as $h)<li><span class="mono muted">{{ substr($h['at'], 0, 16) }}</span> {{ $stages[$h['stage']] ?? $h['stage'] }} — {{ $h['note'] }} <span class="muted">({{ $h['name'] }})</span></li>@endforeach</ul>
            </div>
        </div>
    </div>
@endsection
