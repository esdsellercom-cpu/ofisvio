@extends('layouts.panel')

@section('title', 'Önizleme — '.$content->title)

{{-- Vitrin önizlemesi (faz 48): imzalı site adresi iframe'de; masaüstü / tablet / mobil genişlik. Yayınlanmamış içerik de görünür, noindex. --}}
@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.content.index', ['website' => $content->website_id, 'kind' => $content->kind->value]) }}">İçerik</a> · {{ $content->kind->label() }} · {{ $draft ? 'çalışma taslağı' : $content->status->label() }}</p>
            <h1 class="h2">{{ $content->title }}</h1>
            <p class="small muted" style="margin:6px 0 0">Son kayıtlı sürüm gösterilir; bağlantı 30 dakika geçerlidir ve arama motorlarına kapalıdır.</p>
        </div>
        <div class="panel-head__actions">
            <div class="tabbar" data-preview-devices style="border:0">
                <button type="button" data-width="100%" aria-selected="true">Masaüstü</button>
                <button type="button" data-width="820px">Tablet</button>
                <button type="button" data-width="390px">Mobil</button>
            </div>
            <a href="{{ $editUrl }}" class="btn btn--ghost">Düzenlemeye dön</a>
            <a href="{{ $frameUrl }}" class="btn btn--ghost" target="_blank" rel="noopener">Yeni sekmede aç ↗</a>
        </div>
    </div>

    <div class="card" style="padding:12px;background:var(--surface-2)">
        <div style="margin:0 auto;transition:width .2s;width:100%" data-preview-frame-wrap>
            <iframe src="{{ $frameUrl }}" title="Sayfa önizlemesi" style="width:100%;height:calc(100vh - 220px);min-height:600px;border:1px solid var(--line);border-radius:8px;background:#fff" data-preview-frame></iframe>
        </div>
    </div>
@endsection

@push('scripts')
    <script nonce="{{ csp_nonce() }}" src="{{ asset_v('js/cms.js') }}" defer></script>
@endpush
