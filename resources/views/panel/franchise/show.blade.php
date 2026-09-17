@extends('layouts.panel')

@section('title', 'Franchise başvurusu — '.$app->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.franchise.index') }}">Franchise</a> / #{{ $app->id }}</p>
            <h1 class="h2">{{ $app->name }} · {{ $app->city }}</h1>
            <p><span class="pill {{ ['new' => 'a', 'reviewing' => 'i', 'approved' => 'g', 'rejected' => 'n'][$app->status] }}">{{ $app->statusLabel() }}</span> Başvuru {{ $app->created_at->format('d.m.Y H:i') }}@if ($app->handled_at) · ele alındı {{ $app->handled_at->format('d.m.Y') }}@endif</p>
        </div>
    </div>

    <div class="grid g-2-1">
        <div class="card">
            <div class="card__head"><h3>Başvuru</h3><span class="sub">KVKK rızası {{ $app->consented_at->format('d.m.Y H:i') }}{{ $app->consent_ip ? ' · '.$app->consent_ip : '' }}</span></div>
            <div class="card__body">
                <dl class="kv">
                    <dt>E-posta</dt><dd>{{ $app->email }}</dd>
                    <dt>Telefon</dt><dd>{{ $app->phone ?? '—' }}</dd>
                    <dt>Şehir</dt><dd>{{ $app->city }}{{ $app->district ? ' / '.$app->district : '' }}</dd>
                    <dt>Bütçe (beyan)</dt><dd>{{ $app->budget ?? '—' }}</dd>
                    <dt>Deneyim</dt><dd style="white-space:pre-line">{{ $app->experience ?? '—' }}</dd>
                    <dt>Mesaj</dt><dd style="white-space:pre-line">{{ $app->message ?? '—' }}</dd>
                </dl>
            </div>
        </div>
        <div class="card">
            <div class="card__head"><h3>Değerlendirme</h3></div>
            <div class="card__body">
                @can('franchise.manage')
                    <form method="POST" action="{{ route('panel.franchise.update', $app) }}" class="stack" style="gap:10px">
                        @csrf @method('PUT')
                        <label class="field"><span class="label">Durum</span><select class="control" name="status">@foreach ($statuses as $k => $label)<option value="{{ $k }}" @selected(old('status', $app->status) === $k)>{{ $label }}</option>@endforeach</select></label>
                        <label class="field"><span class="label">Sorumlu</span><select class="control" name="assigned_to"><option value="">—</option>@foreach ($staff as $u)<option value="{{ $u->id }}" @selected((int) old('assigned_to', $app->assigned_to) === $u->id)>{{ $u->name }}</option>@endforeach</select></label>
                        <label class="field"><span class="label">İç not</span><textarea class="control" name="internal_note" maxlength="2000">{{ old('internal_note', $app->internal_note) }}</textarea></label>
                        @error('status')<span class="field-error">{{ $message }}</span>@enderror
                        <div><button type="submit" class="btn btn--brand">Kaydet</button></div>
                    </form>
                @else
                    <dl class="kv">
                        <dt>Sorumlu</dt><dd>{{ $app->assignee?->name ?? '—' }}</dd>
                        <dt>İç not</dt><dd style="white-space:pre-line">{{ $app->internal_note ?? '—' }}</dd>
                    </dl>
                @endcan
            </div>
        </div>
    </div>
@endsection
