@extends('layouts.panel')

@section('title', 'Blok önizleme — '.$preset->name)

{{-- Kayıtlı blok önizlemesi (faz 50): gerçek bileşen, imzalı vitrin çerçevesinde (?preset=ID); cihaz seçici. --}}
@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.content.blocks') }}">Blok kütüphanesi</a> · {{ $typeLabel }} · {{ \App\Models\SiteBlockPreset::CATEGORIES[$preset->category] ?? $preset->category }}@if ($preset->is_global) · 🌐 global @endif</p>
            <h1 class="h2">{{ $preset->name }}</h1>
        </div>
        <div class="panel-head__actions">
            <div class="tabbar" data-preview-devices style="border:0">
                <button type="button" data-width="100%" aria-selected="true">Masaüstü</button>
                <button type="button" data-width="820px">Tablet</button>
                <button type="button" data-width="390px">Mobil</button>
            </div>
            <a href="{{ $editUrl }}" class="btn btn--brand">Tasarımda düzenle</a>
        </div>
    </div>
    <div class="card" style="padding:12px;background:var(--surface-2)">
        <div style="margin:0 auto;transition:width .2s;width:100%" data-preview-frame-wrap>
            <iframe src="{{ $frameUrl }}" title="Blok önizlemesi" style="width:100%;height:calc(100vh - 220px);min-height:520px;border:1px solid var(--line);border-radius:8px;background:#fff" data-preview-frame></iframe>
        </div>
    </div>
@endsection

@push('scripts')
    <script nonce="{{ csp_nonce() }}" src="{{ asset_v('js/cms.js') }}" defer></script>
@endpush
