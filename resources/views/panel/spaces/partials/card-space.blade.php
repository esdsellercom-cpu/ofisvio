{{-- Masa/ofis kartı (faz 46): $s (Space, activeAssignments + assets_count yüklü), $canManage --}}
@php($st = $s->inventoryStatus())
@php($primary = $s->activeAssignments->first())
<article class="inv-card is-{{ $st }}">
    @if ($s->cover)
        <img class="inv-card__cover" src="{{ $s->cover->urlFor(640) }}" alt="{{ $s->cover->alt ?? $s->name }}" loading="lazy">
    @endif
    <div class="inv-card__head">
        <div>
            <div class="inv-card__title"><a href="{{ route('panel.spaces.show', $s->id) }}">{{ $s->name }}</a>@if ($s->code)<span class="code">{{ $s->code }}</span>@endif</div>
            <div class="inv-card__sub">{{ $s->kindLabel() }} · {{ $s->location->name }}@if ($s->floor) · Kat {{ $s->floor }}@endif @if ($s->zone) · {{ $s->zone }}@endif</div>
        </div>
        <span class="pill {{ match ($st) {'available' => 'g', 'assigned' => 'a', 'maintenance' => 'w', default => 'n'} }}">{{ $s->inventoryLabel() }}</span>
    </div>
    <dl>
        <dt>Kapasite</dt><dd>{{ $s->kind === 'desk_flex' ? $s->occupied().' / '.$s->slots().' eşzamanlı üye' : $s->capacity.' kişi' }}</dd>
        @if ($st === 'maintenance')
            <dt>Bakım</dt><dd>{{ $s->maintenance_until?->format('d.m.Y') }} — {{ $s->maintenance_note }}</dd>
        @endif
        @if ($primary)
            <dt>Üye</dt><dd>{{ $primary->user?->name ?? '—' }} <span class="muted">· {{ $primary->company?->legal_name }}</span>@if ($s->activeAssignments->count() > 1) <span class="muted">+{{ $s->activeAssignments->count() - 1 }}</span>@endif</dd>
            <dt>Tahsis</dt><dd class="mono">{{ $primary->starts_on?->format('d.m.Y') }} → {{ $primary->ends_on?->format('d.m.Y') ?? 'süresiz' }}</dd>
        @endif
        <dt>Aylık</dt><dd>{{ money($s->monthly_price) }}</dd>
        <dt>Demirbaş</dt><dd>{{ $s->assets_count ?? 0 }}</dd>
        @if ($s->amenityList() !== [])<dt>Olanak</dt><dd class="small">{{ implode(', ', $s->amenityList()) }}</dd>@endif
        <dt>Güncelleme</dt><dd class="mono small">{{ $s->updated_at?->format('d.m.Y H:i') }}</dd>
    </dl>
    <div class="inv-card__foot">
        <a href="{{ route('panel.spaces.show', $s->id) }}" class="btn btn--quiet">Detay</a>
        @if ($canManage)
            @if ($st === 'available')
                <button type="button" class="btn btn--quiet" data-modal-open="#modal-assign" data-title="Tahsis et · {{ $s->name }}" data-fill="{{ json_encode(['space_id' => $s->id, 'starts_on' => now()->toDateString()]) }}">Tahsis et</button>
            @endif
            <button type="button" class="btn btn--quiet" data-modal-open="#modal-inventory" data-title="Düzenle · {{ $s->name }}" data-method="PUT" data-action="{{ route('panel.spaces.inventory.space.update', $s->id) }}" data-fill="{{ json_encode(['kind' => $s->kind, 'location_id' => $s->location_id, 'name' => $s->name, 'code' => $s->code, 'floor' => $s->floor, 'zone' => $s->zone, 'capacity' => $s->capacity, 'monthly_price' => \App\Support\Money::major($s->monthly_price), 'status' => $s->operationalStatus(), 'maintenance_until' => $s->maintenance_until?->toDateString(), 'maintenance_note' => $s->maintenance_note, 'sort_order' => $s->sort_order, 'notes' => $s->notes, 'amenities' => implode(', ', $s->amenityList()), 'cover_media_id' => $s->cover_media_id]) }}">Düzenle</button>
            <details class="menu" style="margin-left:auto">
                <summary class="btn btn--quiet" aria-label="Diğer işlemler">•••</summary>
                <div class="menu__list">
                    @foreach ($s->activeAssignments as $as)
                        <button type="button" data-modal-open="#modal-assignment" data-title="Tahsisi düzenle · {{ $s->name }}" data-method="PUT" data-action="{{ route('panel.spaces.inventory.assignment.update', $as->id) }}" data-fill="{{ json_encode(['company_id' => $as->company_id, 'space_location' => $s->location_id, 'ends_on' => $as->ends_on?->toDateString(), 'user_id' => $as->user_id, 'note' => $as->note, 'asset_ids' => $as->assets->pluck('id')->all()]) }}">Tahsisi değiştir{{ $as->user ? ' · '.$as->user->name : '' }}</button>
                        <form method="POST" action="{{ route('panel.spaces.inventory.assignment.end', $as->id) }}" data-confirm="Tahsis sonlandırılsın mı? Demirbaşlar serbest kalır.">@csrf<input type="hidden" name="_tab" value="{{ $tab }}"><button type="submit" class="danger">Tahsisi sonlandır{{ $as->user ? ' · '.$as->user->name : '' }}</button></form>
                    @endforeach
                    <button type="button" data-modal-open="#modal-asset" data-title="Demirbaş ekle · {{ $s->name }}" data-fill="{{ json_encode(['location_id' => $s->location_id, 'space_id' => $s->id]) }}">Bu alana demirbaş ekle</button>
                </div>
            </details>
        @endif
    </div>
</article>
