{{-- Menü App\View\Menu\PanelMenu'den gelir (PanelLayoutComposer → $panelMenu): gruplar,
     sırayla numaralı ögeler, izne göre görünürlük, tek sorguluk rozetler. Ölü öge yok. --}}
@foreach ($panelMenu as $group)
    <div class="ap-nav__h">{{ $group['label'] }}</div>
    @foreach ($group['items'] as $item)
        <a href="{{ $item['url'] }}" class="ap-nav__i" @if ($item['active']) aria-current="page" @endif>
            <span class="n" aria-hidden="true">{{ $item['n'] }}</span>
            <span class="t">{{ $item['label'] }}</span>
            @if ($item['badge'] > 0)<span class="c {{ $item['tone'] }}" aria-label="{{ $item['badge'] }} bekleyen">{{ $item['badge'] }}</span>@endif
        </a>
    @endforeach
@endforeach
