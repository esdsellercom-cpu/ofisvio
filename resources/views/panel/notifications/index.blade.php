@extends('layouts.panel')

@section('title', 'Bildirim merkezi')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Bildirim merkezi · kuyruk {{ $counts['queued'] }} · başarısız {{ $counts['failed'] }} · bugün gönderilen {{ $counts['sent_today'] }}</p>
            <h1 class="h2">Bildirimler</h1>
        </div>
        <div class="panel-head__actions">
            <span class="badge badge--{{ $providers['whatsapp'] ? 'ok' : 'muted' }}">WhatsApp {{ $providers['whatsapp'] ? 'açık' : 'kapalı' }}</span>
            <span class="badge badge--{{ $providers['sms'] ? 'ok' : 'muted' }}">SMS {{ $providers['sms'] ? 'açık' : 'kapalı' }}</span>
            <span class="badge badge--muted">E-posta: {{ $providers['email'] }}</span>
        </div>
    </div>

    @error('recipient')<div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror
    @error('body')<div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror

    <nav class="tabs" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px">
        @foreach (['kurallar' => 'Kurallar', 'alicilar' => 'Alıcılar', 'sablonlar' => 'Şablonlar', 'gunluk' => 'Günlük'] as $k => $label)
            <a href="{{ route('panel.notifications.index', ['sekme' => $k]) }}" class="chip" aria-pressed="{{ $tab === $k ? 'true' : 'false' }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if ($tab === 'kurallar')
        <form method="POST" action="{{ route('panel.notifications.rules') }}" class="panel">
            @csrf @method('PUT')
            <p class="small muted" style="margin:0 0 12px">Olay × kanal × alıcı grubu. "Müşteri" grubu olayın muhatabına (talep sahibi) gider; diğerleri Alıcılar sekmesindeki kayıtlara. Sağlayıcısı kapalı kanal "atlandı" olarak günlüğe düşer.</p>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Olay</th>@foreach ($channels as $ck => $cl)<th>{{ $cl }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($events as $ek => $def)
                            <tr>
                                <td><strong>{{ $def['label'] }}</strong><span class="mono small muted" style="display:block">{{ $ek }}</span></td>
                                @foreach ($channels as $ck => $cl)
                                    <td class="small">
                                        @foreach ($groups as $gk => $gl)
                                            <label class="checkbox-row" style="margin:0 0 4px"><input type="checkbox" name="rules[{{ $ek }}][{{ $ck }}][{{ $gk }}]" value="1" @checked($matrix[$ek][$ck][$gk] ?? false) @disabled(! auth()->user()?->can('notification.manage'))><span>{{ $gl }}</span></label>
                                        @endforeach
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @can('notification.manage')<div style="margin-top:14px"><button type="submit" class="btn btn--brand">Kuralları kaydet</button></div>@endcan
        </form>
    @elseif ($tab === 'alicilar')
        <div class="grid-auto" style="--min:340px;--gap:20px;align-items:start">
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Ad</th><th>Kanal</th><th>Adres</th><th>Grup</th><th>Lokasyon</th><th>Durum</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($recipients as $r)
                            <tr>
                                <td>{{ $r->name }}</td>
                                <td>{{ $channels[$r->channel] ?? $r->channel }}</td>
                                <td class="mono small">{{ $r->maskedAddress() }}</td>
                                <td class="small">{{ $groups[$r->group] ?? $r->group }}</td>
                                <td class="small">{{ $r->location?->name ?? 'Tümü' }}</td>
                                <td><span class="badge badge--{{ $r->is_active ? 'ok' : 'muted' }}">{{ $r->is_active ? 'Aktif' : 'Pasif' }}</span></td>
                                <td>
                                    @can('notification.manage')
                                        <div class="row-actions">
                                            {{-- Adres HTML'ye çıkmaz (PII): yalnız durum değişir. --}}
                                            <form method="POST" action="{{ route('panel.notifications.recipients.toggle', $r) }}">@csrf
                                                <button type="submit" class="btn btn--ghost btn--pill">{{ $r->is_active ? 'Pasife al' : 'Etkinleştir' }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('panel.notifications.recipients.destroy', $r) }}" data-confirm="Alıcı silinsin mi?">@csrf @method('DELETE')
                                                <button type="submit" class="btn btn--ghost btn--pill" style="color:var(--danger)">Sil</button>
                                            </form>
                                        </div>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="muted">Alıcı yok. WhatsApp yönetici bildirimi için bir alıcı ekleyin (grup: Rezervasyon yöneticileri).</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @can('notification.manage')
                <form method="POST" action="{{ route('panel.notifications.recipients.store') }}" class="panel stack" style="gap:12px">
                    @csrf
                    <p class="eyebrow" style="margin:0">Alıcı ekle</p>
                    <label class="field"><span class="label">Ad</span><input class="control" type="text" name="name" required minlength="2" maxlength="120" value="{{ old('name') }}" placeholder="Rezervasyon yönetimi"></label>
                    <div class="grid-auto" style="--min:140px;--gap:12px">
                        <label class="field"><span class="label">Kanal</span>
                            <select class="control" name="channel">@foreach ($channels as $ck => $cl)<option value="{{ $ck }}" @selected(old('channel', 'whatsapp') === $ck)>{{ $cl }}</option>@endforeach</select>
                        </label>
                        <label class="field"><span class="label">Grup</span>
                            <select class="control" name="group">@foreach ($groups as $gk => $gl)@continue($gk === 'customer')<option value="{{ $gk }}" @selected(old('group', 'booking_managers') === $gk)>{{ $gl }}</option>@endforeach</select>
                        </label>
                    </div>
                    <label class="field"><span class="label">Adres (telefon E.164 ya da e-posta)</span><input class="control mono" type="text" name="address" value="{{ old('address') }}" placeholder="+905001234567"></label>
                    <label class="field"><span class="label">Uygulama içi alıcı (kanal: uygulama içi)</span>
                        <select class="control" name="user_id"><option value="">—</option>@foreach ($staff as $u)<option value="{{ $u->id }}" @selected((string) old('user_id') === (string) $u->id)>{{ $u->name }} · {{ $u->email }}</option>@endforeach</select>
                    </label>
                    <label class="field"><span class="label">Lokasyon (boş: tümü)</span>
                        <select class="control" name="location_id"><option value="">Tüm lokasyonlar</option>@foreach ($locations as $loc)<option value="{{ $loc->id }}" @selected((string) old('location_id') === (string) $loc->id)>{{ $loc->name }}</option>@endforeach</select>
                    </label>
                    <label class="checkbox-row"><input type="checkbox" name="is_active" value="1" checked><span>Aktif</span></label>
                    <div><button type="submit" class="btn btn--brand">Ekle</button></div>
                </form>
            @endcan
        </div>
    @elseif ($tab === 'sablonlar')
        <div class="panel">
            <form method="GET" class="inline-form" style="margin-bottom:14px">
                <input type="hidden" name="sekme" value="sablonlar">
                <label class="field" style="flex:1 1 240px"><span class="label">Olay</span>
                    <select class="control" name="olay" data-autosubmit>@foreach ($events as $ek => $def)<option value="{{ $ek }}" @selected($templateEvent === $ek)>{{ $def['label'] }}</option>@endforeach</select>
                </label>
                <label class="field" style="flex:0 1 180px"><span class="label">Kanal</span>
                    <select class="control" name="kanal" data-autosubmit>@foreach ($channels as $ck => $cl)<option value="{{ $ck }}" @selected($templateChannel === $ck)>{{ $cl }}</option>@endforeach</select>
                </label>
            </form>
            <form method="POST" action="{{ route('panel.notifications.templates') }}" class="stack" style="gap:12px">
                @csrf @method('PUT')
                <input type="hidden" name="event" value="{{ $templateEvent }}"><input type="hidden" name="channel" value="{{ $templateChannel }}"><input type="hidden" name="locale" value="tr">
                <p class="small muted" style="margin:0">Yer tutucular: @foreach ($events[$templateEvent]['placeholders'] as $p)<code>@{{{{ $p }}}}</code> @endforeach — {{ $template['stored'] ? 'Panelden düzenlenmiş şablon.' : 'Teknik varsayılan gösteriliyor; kaydedince DB\'ye yazılır. Boş kaydetmek varsayılana döndürür.' }}</p>
                @if (in_array($templateChannel, ['email', 'in_app'], true))
                    <label class="field"><span class="label">Konu / başlık</span><input class="control" type="text" name="subject" maxlength="160" value="{{ old('subject', $template['subject']) }}"></label>
                @endif
                <label class="field"><span class="label">Gövde</span><textarea class="control mono" name="body" style="min-height:260px;font-size:13.5px;line-height:1.5">{{ old('body', $template['body']) }}</textarea></label>
                @canany(['notification.manage', 'notification_template.manage'])<div><button type="submit" class="btn btn--brand">Şablonu kaydet</button></div>@endcanany
            </form>
        </div>
    @else
        <form method="GET" class="inline-form" style="margin-bottom:14px">
            <input type="hidden" name="sekme" value="gunluk">
            <label class="field" style="flex:0 1 160px"><span class="label">Durum</span>
                <select class="control" name="durum" data-autosubmit><option value="">Tümü</option>@foreach (\App\Models\NotificationLog::STATUSES as $k => $l)<option value="{{ $k }}" @selected(($logFilters['durum'] ?? '') === $k)>{{ $l }}</option>@endforeach</select>
            </label>
            <label class="field" style="flex:0 1 160px"><span class="label">Kanal</span>
                <select class="control" name="kanal" data-autosubmit><option value="">Tümü</option>@foreach ($channels as $ck => $cl)<option value="{{ $ck }}" @selected(($logFilters['kanal'] ?? '') === $ck)>{{ $cl }}</option>@endforeach</select>
            </label>
        </form>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Zaman</th><th>Olay</th><th>Kanal</th><th>Alıcı</th><th>Sağlayıcı</th><th>Durum</th><th class="num">Deneme</th><th>Hata</th></tr></thead>
                <tbody>
                    @forelse ($logs as $l)
                        <tr>
                            <td class="small mono">{{ $l->created_at?->format('d.m H:i:s') }}</td>
                            <td class="small">{{ $events[$l->event]['label'] ?? $l->event }}@if ($l->entity_type) <span class="mono muted">{{ $l->entity_type }}#{{ $l->entity_id }}</span>@endif</td>
                            <td class="small">{{ $channels[$l->channel] ?? $l->channel }}</td>
                            <td class="small mono">{{ $l->maskedRecipient() }}</td>
                            <td class="small mono">{{ $l->provider ?? '—' }}{{ $l->provider_message_id ? ' · '.\Illuminate\Support\Str::limit($l->provider_message_id, 18) : '' }}</td>
                            <td><span class="badge badge--{{ in_array($l->status, ['sent', 'delivered'], true) ? 'ok' : ($l->status === 'failed' ? 'danger' : ($l->status === 'queued' ? 'warn' : 'muted')) }}">{{ \App\Models\NotificationLog::STATUSES[$l->status] ?? $l->status }}</span></td>
                            <td class="num mono">{{ $l->attempt }}</td>
                            <td class="small">{{ $l->error ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">Kayıt yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:16px">{{ $logs->links() }}</div>
    @endif
@endsection
