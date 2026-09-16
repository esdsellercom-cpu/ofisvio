@extends('layouts.panel')

@section('title', 'Denetim kaydı')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Sistem</p>
            <h1 class="h2">Denetim kaydı</h1>
        </div>
    </div>

    <p class="body-muted" style="margin:0 0 18px;max-width:72ch">
        Salt okunur. JIT erişimleri gerekçe ve süreyle; personelin müşteri organizasyonlarına girişleri IP ile;
        şirket durum geçişleri kim/ne zaman/neden ile kayıtlıdır. Kayıtlar silinmez, düzenlenmez.
    </p>

    <nav class="row-actions" style="gap:8px;flex-wrap:wrap;margin-bottom:16px" aria-label="Kayıt türü">
        @foreach ($types as $key => $label)
            <a href="{{ route('panel.audit.index', ['tur' => $key]) }}" class="btn btn--ghost btn--pill{{ $type === $key ? ' is-active' : '' }}" @if ($type === $key) aria-current="page" @endif>{{ $label }} <span class="muted">({{ $counts[$key] }})</span></a>
        @endforeach
    </nav>

    <form method="GET" class="inline-form" style="margin-bottom:18px">
        <input type="hidden" name="tur" value="{{ $type }}">
        <label class="field" style="flex:1 1 220px"><span class="label">Ara</span>
            <input class="control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120" placeholder="kullanıcı, izin, şirket, IP">
        </label>
        <label class="field" style="flex:0 1 160px"><span class="label">Başlangıç</span><input class="control" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
        <label class="field" style="flex:0 1 160px"><span class="label">Bitiş</span><input class="control" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
        <button type="submit" class="btn btn--ghost">Süz</button>
    </form>

    @if ($rows->isEmpty())
        <div class="empty-state">Bu süzgeçle eşleşen kayıt yok.</div>
    @else
        <div class="table-wrap">
            <table class="data">
                @if ($type === 'jit')
                    <thead><tr><th>Verildi</th><th>Kullanıcı</th><th>İzin</th><th>Kaynak</th><th>Gerekçe</th><th>Bitiş</th><th>Durum</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $r)
                            @php($expired = strtotime((string) $r->expires_at) < time())
                            <tr>
                                <td class="small mono">{{ \Illuminate\Support\Carbon::parse($r->granted_at)->format('d.m.Y H:i') }}</td>
                                <td>{{ $r->user_name }}<span class="small muted mono" style="display:block">{{ $r->user_email }}</span></td>
                                <td class="mono small">{{ $r->permission }}</td>
                                <td class="mono small">{{ $r->resource_type }} #{{ $r->resource_id }}</td>
                                <td class="small">{{ $r->reason }}@if ($r->approved_by_name)<span class="muted"> · onay: {{ $r->approved_by_name }}</span>@endif</td>
                                <td class="small mono">{{ \Illuminate\Support\Carbon::parse($r->expires_at)->format('d.m.Y H:i') }}</td>
                                <td>@if ($r->revoked_at)<span class="badge badge--danger">İptal</span>@elseif ($expired)<span class="badge badge--muted">Doldu</span>@else<span class="badge badge--ok">Etkin</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                @elseif ($type === 'context')
                    <thead><tr><th>Zaman</th><th>Kullanıcı</th><th>Giriş</th><th>Organizasyon</th><th>Önceki</th><th>IP</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td class="small mono">{{ $r->switched_at?->format('d.m.Y H:i') }}</td>
                                <td>{{ $r->user?->name ?? '—' }}<span class="small muted mono" style="display:block">{{ $r->user?->email }}</span></td>
                                <td><span class="badge badge--{{ $r->entry_path === 'staff' ? 'warn' : 'info' }}">{{ $r->entry_path === 'staff' ? 'Personel' : 'Üye' }}</span></td>
                                <td>{{ $r->toOrganization?->name ?? '—' }}</td>
                                <td class="small muted">{{ $r->fromOrganization?->name ?? '—' }}</td>
                                <td class="small mono">{{ $r->ip_address }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @elseif ($type === 'webhook')
                    <thead><tr><th>Alındı</th><th>Sağlayıcı</th><th>Olay</th><th>Durum</th><th>Kaynak IP</th><th>Hata</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td class="small mono">{{ $r->received_at?->format('d.m.Y H:i:s') }}</td>
                                <td class="mono small">{{ $r->provider }}</td>
                                <td class="mono small">{{ $r->event_id }}<span class="muted" style="display:block">{{ $r->payload['type'] ?? '' }}</span></td>
                                <td><span class="badge badge--{{ $r->status === 'processed' ? 'ok' : ($r->status === 'failed' ? 'danger' : 'warn') }}">{{ $r->status }}</span></td>
                                <td class="small mono">{{ $r->source_ip }}</td>
                                <td class="small">{{ $r->error ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @elseif ($type === 'integration')
                    <thead><tr><th>Zaman</th><th>Sağlayıcı</th><th>İstek</th><th class="num">Durum</th><th class="num">Süre</th><th>Hata</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td class="small mono">{{ $r->created_at?->format('d.m.Y H:i:s') }}</td>
                                <td class="mono small">{{ $r->provider }}</td>
                                <td class="mono small">{{ $r->method }} {{ $r->path }}</td>
                                <td class="num"><span class="badge badge--{{ $r->ok ? 'ok' : 'danger' }}">{{ $r->status ?? '—' }}</span></td>
                                <td class="num mono small">{{ $r->duration_ms }} ms</td>
                                <td class="small">{{ $r->error ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @else
                    <thead><tr><th>Zaman</th><th>Şirket</th><th>Geçiş</th><th>Yapan</th><th>Gerekçe</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td class="small mono">{{ $r->created_at?->format('d.m.Y H:i') }}</td>
                                <td>{{ $r->company?->legal_name ?? '—' }}</td>
                                <td class="mono small">{{ $r->from_status?->value ?? "∅" }} → <strong>{{ $r->to_status->value }}</strong></td>
                                <td class="small">{{ $r->performer?->name ?? 'sistem' }}</td>
                                <td class="small">{{ $r->reason ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @endif
            </table>
        </div>
        <div style="margin-top:16px">{{ $rows->links() }}</div>
    @endif
@endsection
