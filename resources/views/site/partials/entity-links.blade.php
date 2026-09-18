{{-- Knowledge Graph bağlantıları (faz 60b): ilgili hizmet / lokasyon / yazı / şehir sayfaları — yalnız var olan kayıtlar.
     $groups: list<array{title: string, items: iterable, url: callable, meta?: callable}> --}}
@php($groups = array_values(array_filter($groups, fn ($g) => count($g['items']) > 0)))
@if ($groups !== [])
    <section style="margin-top:44px" class="entity-links">
        @foreach ($groups as $group)
            <h2 class="label" style="margin:0 0 12px;color:var(--brand)">{{ $group['title'] }}</h2>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:22px">
                @foreach ($group['items'] as $item)
                    <a href="{{ ($group['url'])($item) }}" class="chip">{{ ($group['label'])($item) }}</a>
                @endforeach
            </div>
        @endforeach
    </section>
@endif
