@extends('layouts.panel')

@section('title', 'Talep — '.$lead->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.leads.index') }}">Talepler</a> · {{ $lead->kind === 'booking' ? 'Ön rezervasyon' : ($lead->kind === 'newsletter' ? 'Bülten' : 'Teklif') }}</p>
            <h1 class="h2">{{ $lead->name }}</h1>
        </div>
        <div class="panel-head__actions">
            <span class="badge badge--{{ $lead->status === 'new' ? 'warn' : ($lead->status === 'won' ? 'ok' : ($lead->status === 'lost' ? 'danger' : 'info')) }}">{{ $statuses[$lead->status] ?? $lead->status }}</span>
        </div>
    </div>

    <div class="grid-auto" style="--min:320px;--gap:20px;align-items:start">
        <div class="panel">
            <p class="eyebrow">Talep</p>
            <dl class="dl">
                <dt>E-posta</dt><dd class="mono"><a href="mailto:{{ $lead->email }}">{{ $lead->email }}</a></dd>
                <dt>Telefon</dt><dd class="mono">{{ $lead->phone ?: '—' }}</dd>
                <dt>Çözüm</dt><dd>{{ $lead->solution ?: '—' }}</dd>
                <dt>Ekip</dt><dd>{{ $lead->team_size ?: '—' }}</dd>
                <dt>Lokasyon</dt><dd>{{ $lead->location?->name ?? '—' }}</dd>
                @if ($lead->kind === 'booking')
                    <dt>İstenen zaman</dt><dd>{{ $lead->requested_date?->format('d.m.Y') }} {{ $lead->requested_slot }}</dd>
                @endif
                <dt>Not</dt><dd>{{ $lead->note ?: '—' }}</dd>
                <dt>Geliş</dt><dd>{{ $lead->created_at?->format('d.m.Y H:i') }}</dd>
                <dt>KVKK rızası</dt><dd class="small">{{ $lead->consented_at?->format('d.m.Y H:i') }} · {{ $lead->consent_ip }}</dd>
            </dl>
        </div>

        <div class="panel">
            <p class="eyebrow">İşlem</p>
            @can('lead.assign')
                <form method="POST" action="{{ route('panel.leads.update', $lead) }}" class="stack" style="gap:12px">
                    @csrf @method('PUT')
                    <label class="field"><span class="label">Durum</span>
                        <select class="control" name="status" required>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', $lead->status) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field"><span class="label">Atanan personel</span>
                        <select class="control" name="assigned_to">
                            <option value="">— Atanmamış</option>
                            @foreach ($staff as $u)
                                <option value="{{ $u->id }}" @selected((int) old('assigned_to', $lead->assigned_to) === $u->id)>{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field"><span class="label">İç not</span>
                        <textarea class="control" name="internal_note" maxlength="2000" style="min-height:80px">{{ old('internal_note', $lead->internal_note) }}</textarea>
                    </label>
                    @error('status')<p class="small" style="color:var(--danger);margin:0">{{ $message }}</p>@enderror
                    <div><button type="submit" class="btn btn--brand">Kaydet</button></div>
                </form>
            @else
                <dl class="dl">
                    <dt>Atanan</dt><dd>{{ $lead->assignee?->name ?? '—' }}</dd>
                    <dt>İç not</dt><dd>{{ $lead->internal_note ?: '—' }}</dd>
                </dl>
            @endcan
            @if ($lead->handled_at)<p class="small muted" style="margin:12px 0 0">İlk işlem: {{ $lead->handled_at->format('d.m.Y H:i') }}</p>@endif
        </div>
    </div>
@endsection
