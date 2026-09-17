@extends('layouts.panel')

@section('title', 'Ana sayfa tasarımı')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Sayfa kurucu · {{ $website?->name }} @if ($hasChanges)<span class="badge badge--warn">yayınlanmamış değişiklik</span>@else<span class="badge badge--ok">yayınla eşit</span>@endif</p>
            <h1 class="h2">Ana sayfa tasarımı</h1>
        </div>
        <div class="panel-head__actions">
            @if ($websites->count() > 1)
                <form method="GET" class="inline-form"><select class="control" name="website" onchange="this.form.requestSubmit()">@foreach ($websites as $w)<option value="{{ $w->id }}" @selected($website && $w->id === $website->id)>{{ $w->name }}</option>@endforeach</select></form>
            @endif
            @if ($previewUrl)<a href="{{ $previewUrl }}" target="_blank" rel="noopener" class="btn btn--ghost">Önizle (yeni sekme)</a>@endif
            @can('content.publish')
                @if ($website)
                    <form method="POST" action="{{ route('panel.content.builder.publish', $website) }}" class="inline-form">@csrf
                        <input class="control" type="text" name="note" maxlength="200" placeholder="Yayın notu (isteğe bağlı)" style="min-width:200px">
                        <button type="submit" class="btn btn--brand" @disabled(! $hasChanges)>Yayınla</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>

    @error('builder')<div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror
    <p class="small muted" style="margin:0 0 18px">Taslak değişiklikleri vitrine çıkmaz; <strong>Yayınla</strong> anlık görüntüyü revizyon olarak kaydeder ve site önbelleğini yeniler. Metin kaynakları: <a href="{{ route('panel.content.blocks') }}">Ana sayfa metinleri/blokları</a>; dinamik bölümler (lokasyon, oda, yazı) gerçek kayıtlardan.</p>

    @if ($website)
    <div style="display:grid;grid-template-columns:minmax(280px,1fr) minmax(320px,1.4fr);gap:20px;align-items:start">
        {{-- SOL: bölüm listesi (sürükle-bırak + düğmeler) ve kütüphane --}}
        <div class="stack" style="gap:16px">
            <div class="panel">
                <p class="eyebrow">Bölümler (taslak)</p>
                <form id="reorder-sections" method="POST" action="{{ route('panel.content.builder.reorder', $website) }}">@csrf
                    <input type="hidden" name="order" data-sortable-order value="{{ $sections->pluck('id')->implode(',') }}">
                    <noscript><button type="submit" class="btn btn--ghost" style="margin-bottom:8px">Sırayı kaydet</button></noscript>
                </form>
                <div data-sortable data-sortable-form="reorder-sections">
                    <ol class="stack" style="gap:8px;list-style:none;padding:0;margin:0" data-sortable-list>
                        @foreach ($sections as $sec)
                            @php($def = $library[$sec->type] ?? ['label' => $sec->type, 'source' => ''])
                            <li class="card" draggable="true" data-sortable-item="{{ $sec->id }}" style="padding:10px 12px;display:flex;align-items:center;gap:10px;border-color:{{ $editing && $editing->id === $sec->id ? 'var(--brand)' : 'var(--line)' }};opacity:{{ $sec->is_visible ? 1 : .55 }}">
                                <span class="mono muted" style="cursor:grab" title="Sürükle" aria-hidden="true">⋮⋮</span>
                                <div style="flex:1;min-width:0">
                                    <a href="{{ route('panel.content.builder.index', ['website' => $website->id, 'bolum' => $sec->id]) }}" style="font-weight:600">{{ $def['label'] }}</a>
                                    <span class="small muted" style="display:block">{{ $sec->anchor ? '#'.$sec->anchor.' · ' : '' }}{{ $def['source'] }}@if ($sec->publish_from || $sec->publish_until) · zamanlı @endif @if ($sec->hide_on_mobile) · mobilde gizli @endif @if ($sec->hide_on_desktop) · masaüstünde gizli @endif</span>
                                </div>
                                <div class="row-actions" style="flex:none">
                                    <button type="submit" form="mv-{{ $sec->id }}-up" class="btn btn--ghost btn--pill" title="Yukarı" @disabled($loop->first)>↑</button>
                                    <button type="submit" form="mv-{{ $sec->id }}-down" class="btn btn--ghost btn--pill" title="Aşağı" @disabled($loop->last)>↓</button>
                                    <button type="submit" form="tg-{{ $sec->id }}" class="btn btn--ghost btn--pill" title="{{ $sec->is_visible ? 'Gizle' : 'Göster' }}">{{ $sec->is_visible ? '◉' : '○' }}</button>
                                    @unless ($library[$sec->type]['unique'] ?? false)<button type="submit" form="dp-{{ $sec->id }}" class="btn btn--ghost btn--pill" title="Çoğalt">⧉</button>@endunless
                                    <button type="submit" form="rm-{{ $sec->id }}" class="btn btn--ghost btn--pill" title="Sil" style="color:var(--danger)" onclick="return confirm('Bölüm taslaktan silinsin mi?')">×</button>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
                @foreach ($sections as $sec)
                    <form id="mv-{{ $sec->id }}-up" method="POST" action="{{ route('panel.content.builder.move', [$website, $sec->id]) }}" hidden>@csrf<input type="hidden" name="direction" value="up"></form>
                    <form id="mv-{{ $sec->id }}-down" method="POST" action="{{ route('panel.content.builder.move', [$website, $sec->id]) }}" hidden>@csrf<input type="hidden" name="direction" value="down"></form>
                    <form id="tg-{{ $sec->id }}" method="POST" action="{{ route('panel.content.builder.toggle', [$website, $sec->id]) }}" hidden>@csrf</form>
                    <form id="dp-{{ $sec->id }}" method="POST" action="{{ route('panel.content.builder.duplicate', [$website, $sec->id]) }}" hidden>@csrf</form>
                    <form id="rm-{{ $sec->id }}" method="POST" action="{{ route('panel.content.builder.destroy', [$website, $sec->id]) }}" hidden>@csrf @method('DELETE')</form>
                @endforeach
            </div>

            <div class="panel">
                <p class="eyebrow">Bölüm kütüphanesi</p>
                <form method="POST" action="{{ route('panel.content.builder.store', $website) }}" class="inline-form">@csrf
                    <select class="control" name="type" style="flex:1 1 200px">
                        @foreach ($library as $key => $def)
                            <option value="{{ $key }}" @disabled(($def['unique'] ?? false) && $sections->contains('type', $key))>{{ $def['label'] }} — {{ $def['description'] }}</option>
                        @endforeach
                    </select>
                    <select class="control" name="after" style="flex:0 1 160px"><option value="">Sona ekle</option>@foreach ($sections as $sec)<option value="{{ $sec->id }}">{{ $library[$sec->type]['label'] ?? $sec->type }}'dan sonra</option>@endforeach</select>
                    <button type="submit" class="btn btn--brand">Ekle</button>
                </form>
            </div>

            <div class="panel">
                <p class="eyebrow">Revizyonlar</p>
                <table class="data">
                    <thead><tr><th>#</th><th>Yayın</th><th>Kim</th><th>Not</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($revisions as $rev)
                            <tr>
                                <td class="mono">{{ $rev->number }}</td>
                                <td class="small mono">{{ $rev->published_at->format('d.m.Y H:i') }}</td>
                                <td class="small">{{ $rev->author?->name ?? '—' }}</td>
                                <td class="small">{{ $rev->note ?? '—' }} <span class="muted">({{ count($rev->snapshot) }} bölüm)</span></td>
                                <td>@can('content.publish')@unless ($loop->first)<form method="POST" action="{{ route('panel.content.builder.rollback', [$website, $rev->id]) }}" onsubmit="return confirm('Revizyon {{ $rev->number }} yeniden yayınlansın mı?')">@csrf<button type="submit" class="btn btn--ghost btn--pill">Geri al</button></form>@endunless @endcan</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted">Henüz yayın yok — vitrin varsayılan yerleşimi basıyor.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- SAĞ: seçili bölüm ayarları ya da cihaz önizlemesi --}}
        <div class="stack" style="gap:16px">
            @if ($editing)
                @php($def = $library[$editing->type])
                @php($set = $editing->settings ?? [])
                <form method="POST" action="{{ route('panel.content.builder.update', [$website, $editing->id]) }}" class="panel stack" style="gap:12px">
                    @csrf @method('PUT')
                    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
                        <p class="eyebrow" style="margin:0">{{ $def['label'] }} · ayarlar</p>
                        <span class="small muted">Veri kaynağı: {{ $def['source'] }}</span>
                    </div>
                    @foreach ($def['fields'] as $key => $field)
                        @if ($field['type'] === 'cta')
                            @php($cta = $set['cta'] ?? ['action' => 'none', 'target' => '', 'label' => ''])
                            <fieldset class="stack" style="gap:8px;border:1px solid var(--line);border-radius:var(--r-md);padding:12px">
                                <legend class="label">{{ $field['label'] }}</legend>
                                <div class="grid-auto" style="--min:160px;--gap:10px">
                                    <label class="field"><span class="label">Eylem</span>
                                        <select class="control" name="settings[{{ $key }}][action]">@foreach ($ctaActions as $ak => $al)<option value="{{ $ak }}" @selected(($cta['action'] ?? 'none') === $ak)>{{ $al }}</option>@endforeach</select>
                                    </label>
                                    <label class="field"><span class="label">Hedef (çapa adı / sayfa yolu / https adres)</span><input class="control mono" type="text" name="settings[{{ $key }}][target]" value="{{ $cta['target'] ?? '' }}" maxlength="200"></label>
                                    <label class="field"><span class="label">Düğme metni</span><input class="control" type="text" name="settings[{{ $key }}][label]" value="{{ $cta['label'] ?? '' }}" maxlength="60"></label>
                                </div>
                                <span class="small muted">Telefon/WhatsApp/e-posta hedefi site ayarından gelir; ayar boşsa düğme basılmaz.</span>
                            </fieldset>
                        @elseif ($field['type'] === 'textarea' || $field['type'] === 'markdown' || $field['type'] === 'lines')
                            <label class="field"><span class="label">{{ $field['label'] }}</span>
                                <textarea class="control {{ $field['type'] === 'markdown' ? 'mono' : '' }}" name="settings[{{ $key }}]" style="min-height:{{ $field['type'] === 'textarea' ? 70 : 160 }}px">{{ old('settings.'.$key, is_array($set[$key] ?? null) ? implode("\n", $set[$key]) : ($set[$key] ?? '')) }}</textarea>
                            </label>
                        @elseif ($field['type'] === 'select')
                            <label class="field"><span class="label">{{ $field['label'] }}</span>
                                <select class="control" name="settings[{{ $key }}]">@foreach ($field['options'] ?? [] as $ov => $ol)<option value="{{ $ov }}" @selected(($set[$key] ?? array_key_first($field['options'])) === $ov)>{{ $ol }}</option>@endforeach</select>
                            </label>
                        @else
                            <label class="field"><span class="label">{{ $field['label'] }}</span><input class="control" type="text" name="settings[{{ $key }}]" value="{{ old('settings.'.$key, $set[$key] ?? '') }}" maxlength="300"></label>
                        @endif
                    @endforeach
                    <div class="grid-auto" style="--min:160px;--gap:10px">
                        <label class="field"><span class="label">Çapa (#id)</span><input class="control mono" type="text" name="anchor" value="{{ old('anchor', $editing->anchor) }}" maxlength="40" pattern="[a-z0-9-]{2,40}"></label>
                        <label class="field"><span class="label">Yayın başlangıcı</span><input class="control mono" type="datetime-local" name="publish_from" value="{{ old('publish_from', $editing->publish_from?->format('Y-m-d\TH:i')) }}"></label>
                        <label class="field"><span class="label">Yayın bitişi</span><input class="control mono" type="datetime-local" name="publish_until" value="{{ old('publish_until', $editing->publish_until?->format('Y-m-d\TH:i')) }}"></label>
                    </div>
                    <div style="display:flex;gap:16px;flex-wrap:wrap">
                        <label class="checkbox-row"><input type="checkbox" name="is_visible" value="1" @checked(old('is_visible', $editing->is_visible))><span>Görünür</span></label>
                        <label class="checkbox-row"><input type="checkbox" name="hide_on_mobile" value="1" @checked(old('hide_on_mobile', $editing->hide_on_mobile))><span>Mobilde gizle</span></label>
                        <label class="checkbox-row"><input type="checkbox" name="hide_on_desktop" value="1" @checked(old('hide_on_desktop', $editing->hide_on_desktop))><span>Masaüstünde gizle</span></label>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="btn btn--brand">Taslağa kaydet</button>
                        <a href="{{ route('panel.content.builder.index', ['website' => $website->id]) }}" class="btn btn--ghost">Önizlemeye dön</a>
                    </div>
                </form>
            @else
                <div class="panel">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
                        <p class="eyebrow" style="margin:0">Canlı önizleme (taslak)</p>
                        <div style="display:flex;gap:6px">
                            @foreach (['desktop' => 'Masaüstü', 'tablet' => 'Tablet', 'mobile' => 'Mobil'] as $dk => $dl)
                                <a href="{{ route('panel.content.builder.index', ['website' => $website->id, 'cihaz' => $dk]) }}" class="chip" aria-pressed="{{ $device === $dk ? 'true' : 'false' }}">{{ $dl }}</a>
                            @endforeach
                        </div>
                    </div>
                    <iframe src="{{ $previewUrl }}" title="Ana sayfa önizleme" class="builder-frame builder-frame--{{ $device }}" loading="lazy"></iframe>
                </div>
            @endif
        </div>
    </div>
    @endif
@endsection
