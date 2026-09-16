@extends('layouts.panel')

@section('title', 'Talepler')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">CRM</p>
            <h1 class="h2">Talepler</h1>
        </div>
    </div>

    <form method="GET" class="inline-form" style="margin-bottom:18px">
        <label class="field" style="flex:1 1 220px"><span class="label">Ara (ad, e-posta, telefon)</span>
            <input class="control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120">
        </label>
        <label class="field" style="flex:0 1 160px"><span class="label">Tür</span>
            <select class="control" name="kind">
                <option value="">Tümü</option>
                <option value="quote" @selected(($filters['kind'] ?? '') === 'quote')>Teklif</option>
                <option value="booking" @selected(($filters['kind'] ?? '') === 'booking')>Ön rezervasyon</option>
            </select>
        </label>
        <label class="field" style="flex:0 1 180px"><span class="label">Durum</span>
            <select class="control" name="status">
                <option value="">Tümü</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn btn--ghost">Süz</button>
    </form>

    @if ($leads->isEmpty())
        <div class="empty-state">Bu süzgeçle eşleşen talep yok. Talepler vitrindeki teklif ve toplantı odası formlarından gelir.</div>
    @else
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Tarih</th><th>Tür</th><th>Kişi</th><th>Çözüm / Lokasyon</th><th>Durum</th><th>Atanan</th><th></th></tr></thead>
                <tbody>
                    @foreach ($leads as $lead)
                        <tr>
                            <td class="small mono">{{ $lead->created_at?->format('d.m.Y H:i') }}</td>
                            <td>{{ $lead->kind === 'booking' ? 'Ön rezervasyon' : 'Teklif' }}</td>
                            <td><a href="{{ route('panel.leads.show', $lead) }}">{{ $lead->name }}</a><span class="small muted mono" style="display:block">{{ $lead->email }}</span></td>
                            @php($detail = array_filter([$lead->solution, $lead->location?->name, $lead->requested_date ? $lead->requested_date->format('d.m.Y').' '.$lead->requested_slot : null]))
                            <td class="small">{{ $detail === [] ? '—' : implode(' · ', $detail) }}</td>
                            <td><span class="badge badge--{{ $lead->status === 'new' ? 'warn' : ($lead->status === 'won' ? 'ok' : ($lead->status === 'lost' ? 'danger' : 'info')) }}">{{ $statuses[$lead->status] ?? $lead->status }}</span></td>
                            <td class="small">{{ $lead->assignee?->name ?? '—' }}</td>
                            <td><div class="row-actions"><a href="{{ route('panel.leads.show', $lead) }}" class="btn btn--ghost btn--pill">Aç</a></div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top:16px">{{ $leads->links() }}</div>
    @endif
@endsection
