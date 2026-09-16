{{-- Ofisvio tasarım sistemine uygun sayfalama (Tailwind sınıfı yok). Paginator::defaultView ile bağlı. --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Sayfalama" class="row-actions" style="gap:8px;flex-wrap:wrap;align-items:center">
        @if ($paginator->onFirstPage())
            <span class="btn btn--ghost btn--pill" aria-disabled="true" style="opacity:.5">‹ Önceki</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn--ghost btn--pill">‹ Önceki</a>
        @endif
        <span class="small muted mono">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} / {{ $paginator->total() }}</span>
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn--ghost btn--pill">Sonraki ›</a>
        @else
            <span class="btn btn--ghost btn--pill" aria-disabled="true" style="opacity:.5">Sonraki ›</span>
        @endif
    </nav>
@endif
