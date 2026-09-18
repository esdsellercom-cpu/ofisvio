{{-- Sosyal ağ simgesi (faz 61a): küçük, satır içi SVG; $network SiteChromeService::SOCIAL anahtarı. --}}
@php($label = \App\Services\SiteChromeService::SOCIAL[$network] ?? $network)
<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($network)
        @case('instagram')<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>@break
        @case('facebook')<path d="M14 9h3V5h-3c-2.2 0-4 1.8-4 4v2H7v4h3v6h4v-6h3l1-4h-4V9z"/>@break
        @case('linkedin')<rect x="3" y="9" width="4" height="12"/><circle cx="5" cy="5" r="2"/><path d="M11 21v-7a3 3 0 0 1 6 0v7M11 9v12"/>@break
        @case('x')<path d="M4 4l16 16M20 4L4 20"/>@break
        @case('youtube')<rect x="2" y="6" width="20" height="12" rx="4"/><path d="M10 9l5 3-5 3z" fill="currentColor" stroke="none"/>@break
        @case('tiktok')<path d="M14 4v9a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 4c.5 2.5 2.5 4 5 4"/>@break
        @default<path d="M20 12a8 8 0 0 1-11.6 7.1L4 20l1-4.2A8 8 0 1 1 20 12z"/>
    @endswitch
</svg><span class="sr-only">{{ $label }}</span>
