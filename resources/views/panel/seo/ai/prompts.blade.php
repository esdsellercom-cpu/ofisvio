@extends('layouts.panel')

@section('title', 'Prompt Registry — '.$website->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.ai.index', $website) }}">AI Content Engine</a> · {{ $website->name }}</p>
            <h1 class="h2">Prompt Registry</h1>
            <p>Sürümlü prompt'lar: yeni sürüm açılınca eski pasifleşir ama iş kayıtları ürettikleri sürümü referans olarak taşır (izlenebilirlik). Yer tutucular: <span class="mono">{topic} {brief} {keywords} {brand} {services} {locations} {body} {title} {city}</span> — yalnız veritabanındaki gerçek bilgiyle dolar.</p>
        </div>
    </div>

    @if ($canEdit)
        <form method="POST" action="{{ route('panel.seo.ai.prompts.store', $website) }}" class="panel stack" style="gap:10px;margin-bottom:18px">
            @csrf
            <p class="eyebrow" style="margin:0">Yeni sürüm</p>
            <div class="grid-auto" style="--min:200px;--gap:8px">
                <label class="field"><span class="label">Anahtar</span><select class="control" name="key">@foreach ($keys as $k => $l)<option value="{{ $k }}" @selected(old('key') === $k)>{{ $l }}</option>@endforeach</select></label>
                <label class="field"><span class="label">Ad</span><input class="control" type="text" name="name" value="{{ old('name') }}" maxlength="120"></label>
                <label class="field"><span class="label">Model (boş = varsayılan)</span><input class="control mono" type="text" name="model" value="{{ old('model') }}" maxlength="80"></label>
            </div>
            <label class="field"><span class="label">Sistem metni</span><textarea class="control" name="system" required maxlength="4000" style="min-height:90px">{{ old('system') }}</textarea></label>
            <label class="field"><span class="label">Şablon</span><textarea class="control mono" name="template" required maxlength="12000" style="min-height:220px;font-size:13px">{{ old('template') }}</textarea></label>
            <div><button type="submit" class="btn btn--brand">Sürümü kaydet ve aktif et</button></div>
        </form>
    @endif

    <div class="stack" style="gap:14px">
        @foreach ($prompts as $p)
            <details class="panel" @if ($p->is_active) open @endif>
                <summary style="cursor:pointer;font-weight:600">{{ $keys[$p->key] ?? $p->key }} — sürüm {{ $p->version }} @if ($p->is_active)<span class="badge badge--ok">aktif</span>@else<span class="badge badge--muted">pasif</span>@endif <span class="small muted">· {{ $p->name }} · model {{ $p->model ?: 'varsayılan' }} · {{ $p->created_at->format('d.m.Y') }}</span></summary>
                <p class="small" style="margin:10px 0 4px"><strong>Sistem:</strong> {{ $p->system }}</p>
                <pre class="mono small" style="white-space:pre-wrap;word-break:break-word;background:var(--surface-2);padding:12px;border-radius:var(--r-sm);margin:0">{{ $p->template }}</pre>
            </details>
        @endforeach
    </div>
@endsection
