{{-- Bölüm: hero → site.partials.hero ($s: bölüm ayarları, $anchor: çapa) --}}
@php($heroCta = app(\App\Services\SiteBuilderService::class)->cta($s['cta'] ?? null, $currentWebsite))
@include('site.partials.hero')
